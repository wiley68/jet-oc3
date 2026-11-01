<?php
/**
 * Предварително зададени визуални схеми за широкия (персонализиран) JET бутон.
 * Съвместим с jetcredit за WooCommerce (същите стойности).
 */
class JetButtonScheme {

	/**
	 * @var array<int, array{short:string,label:string,background:string,hover:string,border:string,color:string,color_second:string}>
	 */
	private static $schemes = array(
		array(
			'short' => 'Класик',
			'label' => 'Класик — зелена рамка',
			'background' => '#ffffff',
			'hover' => '#f0fdf4',
			'border' => '#166534',
			'color' => '#166534',
			'color_second' => '#000000',
		),
		array(
			'short' => 'Синьо',
			'label' => 'Синьо — като WooCommerce',
			'background' => '#f6f7f7',
			'hover' => '#e8f4fc',
			'border' => '#2271b1',
			'color' => '#1d2327',
			'color_second' => '#50575e',
		),
		array(
			'short' => 'Тъмно',
			'label' => 'Тъмна тема',
			'background' => '#1e293b',
			'hover' => '#334155',
			'border' => '#475569',
			'color' => '#f8fafc',
			'color_second' => '#94a3b8',
		),
		array(
			'short' => 'Топло',
			'label' => 'Топли акценти',
			'background' => '#fff7ed',
			'hover' => '#ffedd5',
			'border' => '#ea580c',
			'color' => '#9a3412',
			'color_second' => '#7c2d12',
		),
		array(
			'short' => 'Лилав',
			'label' => 'Лилав акцент',
			'background' => '#faf5ff',
			'hover' => '#f3e8ff',
			'border' => '#7c3aed',
			'color' => '#5b21b6',
			'color_second' => '#6d28d9',
		),
		array(
			'short' => 'Роза',
			'label' => 'Розов акцент',
			'background' => '#fff1f2',
			'hover' => '#ffe4e6',
			'border' => '#e11d48',
			'color' => '#881337',
			'color_second' => '#9f1239',
		),
		array(
			'short' => 'Сиво',
			'label' => 'Минимално сиво',
			'background' => '#ffffff',
			'hover' => '#f4f4f5',
			'border' => '#a1a1aa',
			'color' => '#18181b',
			'color_second' => '#71717a',
		),
	);

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function getSchemes() {
		return self::$schemes;
	}

	/**
	 * @param mixed $index
	 * @return int
	 */
	public static function normalizeIndex($index) {
		$max = count(self::$schemes) - 1;
		$i = (int) $index;
		if ($i < 0 || $i > $max) {
			return 0;
		}
		return $i;
	}

	/**
	 * @param int $index
	 * @return array<string, mixed>|null
	 */
	public static function getScheme($index) {
		$i = self::normalizeIndex($index);
		return isset(self::$schemes[$i]) ? self::$schemes[$i] : null;
	}

	/**
	 * Inline style с CSS променливи за .jet_wide_button_wrap (и админ прегледа).
	 *
	 * @param mixed $index
	 * @return string
	 */
	public static function wrapInlineStyle($index) {
		$s = self::getScheme($index);
		if (!is_array($s)) {
			return '';
		}
		$bg = self::sanitizeColor($s['background']);
		$hover = self::sanitizeColor($s['hover']);
		$bd = self::sanitizeColor($s['border']);
		$col = self::sanitizeColor($s['color']);
		$col2 = self::sanitizeColor($s['color_second']);

		return sprintf(
			'--jet-wide-bg:%s;--jet-wide-hover:%s;--jet-wide-border:%s;--jet-wide-color:%s;--jet-wide-color-second:%s;',
			$bg,
			$hover,
			$bd,
			$col,
			$col2
		);
	}

	/**
	 * @param string $color
	 * @return string
	 */
	private static function sanitizeColor($color) {
		$c = is_string($color) ? trim($color) : '';
		if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $c)) {
			return strtolower($c);
		}

		return '#000000';
	}
}
