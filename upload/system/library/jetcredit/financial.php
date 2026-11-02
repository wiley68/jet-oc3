<?php
/** Pure EUR validation/calculation. No OpenCart writes or delivery side effects. */
class JetCreditFinancialException extends RuntimeException {}

/** Carries only bounded diagnostic metadata, never the rejected value. */
class JetCreditIntegerException extends JetCreditFinancialException {
	private $diagnosticField; private $diagnosticCategory;
	public function __construct($field, $category) {
		parent::__construct('invalid_integer');
		$this->diagnosticField = in_array($field, array('session_order_id','order_product_id','order_quantity','cart_product_id','cart_quantity','cart_minimum','cart_id','option_id','option_value_id','reward','initial_term','currency_id'), true) ? $field : 'integer';
		$this->diagnosticCategory = in_array($category, array('missing','empty','zero','wrong_type','invalid_format','out_of_range'), true) ? $category : 'invalid_format';
	}
	public function field() { return $this->diagnosticField; }
	public function category() { return $this->diagnosticCategory; }
}
require_once __DIR__ . '/diagnostics.php';

class JetCreditFinancial {
	private const JETCREDIT_FINANCIAL_MAX_ITERATIONS = 128;
	private const JETCREDIT_FINANCIAL_PRECISION = 1.0e-08;
	public const TERMS = array(3, 6, 9, 10, 12, 15, 18, 24, 30, 36);

	public static function number($value) {
		if ((!is_int($value) && !is_float($value) && !is_string($value)) || (is_string($value) && (strlen($value) > 40 || !preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D', $value)))) {
			throw new JetCreditFinancialException('invalid_number');
		}
		$value = (float)$value;
		if (!is_finite($value) || abs($value) > 99999999999.99) {
			throw new JetCreditFinancialException('invalid_number');
		}
		return $value;
	}

	public static function money($value) {
		$value = self::number($value);
		if (abs($value - round($value, 2)) > 1.0e-8) {
			throw new JetCreditFinancialException('invalid_money');
		}
		return $value;
	}

	public static function positiveInteger($value) {
		if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) || (float)$value > 2147483647) {
			throw new JetCreditFinancialException('invalid_integer');
		}
		return (int)$value;
	}

	public static function percent($value) {
		$value = self::money($value);
		if ($value < 0 || $value > 999.99) {
			throw new JetCreditFinancialException('invalid_percent');
		}
		return $value;
	}

	public static function minimum($configured) {
		if ($configured === null || $configured === '') {
			return 75.0;
		}
		$value = self::money($configured);
		if ($value < 0) {
			throw new JetCreditFinancialException('invalid_configuration');
		}
		return max(75.0, $value);
	}

	public static function rules() {
		return array('currency' => 'EUR', 'min_principal' => 75, 'initial_threshold' => 125, 'extended_threshold' => 201, 'long_threshold' => 301, 'min_monthly' => 10, 'base_terms' => array(3, 6, 9, 10, 12));
	}

	public static function terms($amount) {
		$amount = self::money($amount);
		$terms = array(3, 6, 9, 10, 12);
		if ($amount >= 201) { $terms = array_merge($terms, array(15, 18, 24)); }
		if ($amount >= 301) { $terms = array_merge($terms, array(30, 36)); }
		return $terms;
	}

	public static function initialTerm($amount, $default) {
		return self::money($amount) < 125 ? 9 : JetCreditDiagnostics::integer($default === null || $default === '' ? 12 : $default, 'initial_term');
	}

	public static function calculate($amount, $down, $term, $percent, $minimum = 75, $requireMonthly = true) {
		$amount = self::money($amount);
		$down = self::money($down);
		$term = self::positiveInteger($term);
		$percent = self::percent($percent);
		$principal = $amount - $down;
		if ($amount <= 0 || $down < 0 || $down >= $amount) { throw new JetCreditFinancialException('invalid_down_payment'); }
		if ((int)round($amount * 100) - (int)round($down * 100) < (int)round(self::minimum($minimum) * 100)) { throw new JetCreditFinancialException('principal_below_minimum'); }
		if (!in_array($term, self::terms($amount), true)) { throw new JetCreditFinancialException('invalid_term'); }
		// Preserve OC3 1.9.1's formula, RATE and total-before-monthly-rounding convention.
		$monthly = (($principal / $term) * (1 + ($term * $percent) / 100));
		$rate = self::RATE($term, $monthly, -1 * $principal) * 12;
		$glp = (self::RATE($term, $monthly, -1 * $principal) * 12) * 100;
		$gpr = (pow((1 + $rate / 12), 12) - 1) * 100;
		$total = $monthly * $term;
		foreach (array($monthly, $glp, $gpr, $total) as $result) {
			if (!is_finite($result)) { throw new JetCreditFinancialException('invalid_financial_result'); }
		}
		$eligible = (float)number_format($monthly, 2, '.', '') >= 10;
		if ($requireMonthly && !$eligible) { throw new JetCreditFinancialException('monthly_below_minimum'); }
		return array('amount' => number_format($amount, 2, '.', ''), 'down' => number_format($down, 2, '.', ''), 'principal' => number_format($principal, 2, '.', ''), 'term' => $term, 'percent' => number_format($percent, 2, '.', ''), 'monthly' => number_format($monthly, 2, '.', ''), 'glp' => number_format($glp, 2, '.', ''), 'gpr' => number_format($gpr, 2, '.', ''), 'total' => number_format($total, 2, '.', ''), 'eligible' => $eligible);
	}

	public static function requireEur($config, $session, $currency) {
		if (($session->data['currency'] ?? null) !== 'EUR') { throw new JetCreditFinancialException('non_eur_currency'); }
		if (!$currency->has('EUR') || !JetCreditDiagnostics::integer($currency->getId('EUR'), 'currency_id') || self::number($currency->getValue('EUR')) <= 0) { throw new JetCreditFinancialException('invalid_eur_currency'); }
		// EUR's enabled flag is checked by the OC3 adapter against the currency row.
	}

	public static function RATE($nper, $pmt, $pv, $fv = 0.0, $type = 0, $guess = 0.1) {
		$rate = $guess;
		if (abs($rate) < self::JETCREDIT_FINANCIAL_PRECISION) {
			$y = $pv * (1 + $nper * $rate) + $pmt * (1 + $rate * $type) * $nper + $fv;
		} else {
			$f = exp($nper * log(1 + $rate));
			$y = $pv * $f + $pmt * (1 / $rate + $type) * ($f - 1) + $fv;
		}
		$y0 = $pv + $pmt * $nper + $fv;
		$y1 = $pv * $f + $pmt * (1 / $rate + $type) * ($f - 1) + $fv;
		$i  = $x0 = 0.0;
		$x1 = $rate;
		while ((abs($y0 - $y1) > self::JETCREDIT_FINANCIAL_PRECISION) && ($i < self::JETCREDIT_FINANCIAL_MAX_ITERATIONS)) {
			$rate = ($y1 * $x0 - $y0 * $x1) / ($y1 - $y0);
			$x0 = $x1;
			$x1 = $rate;
			if (abs($rate) < self::JETCREDIT_FINANCIAL_PRECISION) {
				$y = $pv * (1 + $nper * $rate) + $pmt * (1 + $rate * $type) * $nper + $fv;
			} else {
				$f = exp($nper * log(1 + $rate));
				$y = $pv * $f + $pmt * (1 / $rate + $type) * ($f - 1) + $fv;
			}
			$y0 = $y1;
			$y1 = $y;
			++$i;
		}
		return $rate;
	}
}
