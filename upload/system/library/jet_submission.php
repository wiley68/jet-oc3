<?php
require_once __DIR__ . '/jet_submission_store.php';

/** Internal foundation only. Callers must validate finances before claim().
 * No order, mail, history, cart or session operations are performed here.
 */
class JetSubmission {
    private $store;
    private const ACTIONS = ['order','bank','admin','customer','history','clear'];
    private const TERMINAL = ['done','failed','uncertain','skipped'];

    public function __construct(JetSubmissionStore $store) { $this->store = $store; }

    private function id($id) {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{48}$/D', $id)) throw new InvalidArgumentException('Invalid intent identity');
        return $id;
    }

    private function storeId($id) {
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', (string)$id) || (float)$id > 2147483647) throw new InvalidArgumentException('Invalid store identity');
        return (string)$id;
    }

    private function orderId($id) {
        $id = $this->storeId($id);
        if ($id === '0') throw new InvalidArgumentException('Invalid native order identity');
        return $id;
    }

    private function owner($owner) {
        if (!is_string($owner) || $owner === '' || strlen($owner) > 1024) throw new InvalidArgumentException('Invalid owner identity');
        return $this->store->digest('owner', $owner);
    }

    private function canonical($value, $depth = 0) {
        if ($depth > 32 || is_object($value) || is_resource($value) || (is_float($value) && !is_finite($value))) throw new InvalidArgumentException('Invalid submission data');
        if (!is_array($value)) return $value;
        ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonical($item, $depth + 1);
        return $value;
    }

    private function payload($data) {
        if (!is_array($data) || !$data) throw new InvalidArgumentException('Submission data required');
        return $this->store->digest('payload', json_encode($this->canonical($data), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function scope($scope) {
        if (!is_string($scope) || $scope === '' || strlen($scope) > 4096) throw new InvalidArgumentException('Invalid submission scope');
        return $this->store->digest('scope', $scope);
    }

    private function safe($row) {
        unset($row['owner_digest'], $row['claim_digest'], $row['scope_digest'], $row['payload_digest']);
        return $row;
    }

    public function createIntent($storeId, $owner, $family, $scope, $checkoutOrder = null) {
        if (!in_array($family, ['product','cart','checkout'], true)) throw new InvalidArgumentException('Invalid financing family');
        if (($family === 'checkout') !== ($checkoutOrder !== null)) throw new InvalidArgumentException('Checkout order binding required');
        return $this->safe($this->store->create($this->storeId($storeId), $this->owner($owner), $family, $this->scope($scope), $checkoutOrder === null ? null : $this->orderId($checkoutOrder)));
    }

    public function resolve($id, $storeId, $owner) {
        return $this->safe($this->store->read($this->id($id), $this->owner($owner), $this->storeId($storeId)));
    }

    /** Identity/ownership only; new execution still requires the native F01 validator.
     * $sessionOrderId and $order come from server session/native order model, never POST.
     * Replay deliberately does not depend on current pricing/payment eligibility.
     */
    public function authenticateCheckoutOrder($storeId, $sessionOrderId, $order, $customerId, $guestEmail) {
        if ((!is_int($sessionOrderId) && !is_string($sessionOrderId)) || !ctype_digit((string)$sessionOrderId)
            || (float)$sessionOrderId <= 0 || (float)$sessionOrderId > 2147483647) throw new JetSubmissionStorageException('Checkout order unavailable');
        $id = (string)(int)$sessionOrderId;
        if (!is_array($order) || (string)($order['order_id'] ?? '') !== $id
            || !isset($order['store_id'], $order['customer_id']) || (string)$order['store_id'] !== $this->storeId($storeId)
            || ($order['currency_code'] ?? '') !== 'EUR') throw new JetSubmissionStorageException('Checkout order unavailable');
        if ($customerId !== null) {
            if ($this->orderId($customerId) !== (string)$order['customer_id']) throw new JetSubmissionStorageException('Checkout owner unavailable');
        } elseif ((int)$order['customer_id'] !== 0 || !is_string($guestEmail) || $guestEmail !== ($order['email'] ?? null)) {
            throw new JetSubmissionStorageException('Checkout owner unavailable');
        }
        return $id;
    }

    public function resolveCheckout($storeId, $owner, $orderId) {
        $row = $this->store->findCheckout($this->storeId($storeId), $this->owner($owner), $this->orderId($orderId));
        if ($row !== null && $row['family'] !== 'checkout') throw new JetSubmissionStorageException('Checkout identity conflict');
        return $row === null ? null : $this->safe($row);
    }

    public function updateDraft($id, $storeId, $owner, $scope) {
        $digest = $this->scope($scope);
        return $this->safe($this->store->change($this->id($id), $this->owner($owner), $this->storeId($storeId), function ($row) use ($digest) {
            if ($row['state'] !== 'draft') throw new JetSubmissionStorageException('Submission already claimed');
            return ['scope_digest'=>$digest];
        }));
    }

    /** Winner alone receives an execution token, and only after acknowledged COMMIT.
     * A lost COMMIT response throws; resolve/replay on a NEW connection, never execute.
     */
    public function claim($id, $storeId, $owner, $channel, $validatedData) {
        if (!in_array($channel, ['jet','jet_card'], true)) throw new InvalidArgumentException('Invalid financing channel');
        $token = bin2hex(random_bytes(32));
        $result = $this->store->claim($this->id($id), $this->owner($owner), $this->storeId($storeId), $this->payload($validatedData), $channel, hash('sha256', $token));
        $result['row'] = $this->safe($result['row']);
        if ($result['acquired']) $result['execution_token'] = $token;
        return $result;
    }

    public function replay($id, $storeId, $owner, $channel, $submittedData) {
        if (!in_array($channel, ['jet','jet_card'], true)) throw new InvalidArgumentException('Invalid financing channel');
        $row = $this->store->read($this->id($id), $this->owner($owner), $this->storeId($storeId));
        if ($row['state'] === 'draft' || $row['channel'] !== $channel || !hash_equals($row['payload_digest'], $this->payload($submittedData))) throw new JetSubmissionStorageException('Submission data conflict');
        return $this->safe($row);
    }

    private function action($action) {
        if (!in_array($action, self::ACTIONS, true)) throw new InvalidArgumentException('Invalid submission action');
        return $action . '_state';
    }

    private function mutate($id, $storeId, $owner, $token, $callback) {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) throw new InvalidArgumentException('Invalid execution token');
        return $this->safe($this->store->change($this->id($id), $this->owner($owner), $this->storeId($storeId), function ($row) use ($token, $callback) {
            if ($row['state'] !== 'processing' || !hash_equals($row['claim_digest'], hash('sha256', $token))) throw new JetSubmissionStorageException('Submission execution unavailable');
            return $callback($row);
        }));
    }

    private function primarySuccess($row) { return in_array($row['bank_state'], ['done','skipped'], true); }

    private function canStart($row, $action) {
        if ($row[$this->action($action)] !== 'pending') return false;
        if ($action === 'order') return $row['family'] !== 'checkout';
        if ($row['order_state'] !== 'done' || empty($row['native_order_id'])) return false;
        if ($action === 'bank') return true;
        if ($action === 'admin' || $action === 'customer') return in_array($row['bank_state'], self::TERMINAL, true);
        if (!$this->primarySuccess($row) || !in_array($row['admin_state'], self::TERMINAL, true) || !in_array($row['customer_state'], self::TERMINAL, true)) return false;
        return $action === 'history' || ($row['family'] === 'cart' && in_array($row['history_state'], self::TERMINAL, true));
    }

    public function startAction($id, $storeId, $owner, $token, $action) {
        $field = $this->action($action);
        return $this->mutate($id,$storeId,$owner,$token,function ($row) use ($action,$field) {
            if (!$this->canStart($row,$action)) throw new JetSubmissionStorageException('Action cannot start');
            return [$field=>'started'];
        });
    }

    public function finishAction($id, $storeId, $owner, $token, $action, $outcome) {
        $field = $this->action($action);
        if (!in_array($outcome, ['done','failed','uncertain'], true) || ($action === 'order' && $outcome !== 'uncertain')) throw new InvalidArgumentException('Invalid action outcome');
        return $this->mutate($id,$storeId,$owner,$token,function ($row) use ($field,$outcome) {
            if ($row[$field] !== 'started') throw new JetSubmissionStorageException('Action not started');
            return [$field=>$outcome];
        });
    }

    /** Bank omitted means current manual-registration behavior; not a mail retry. */
    public function skipAction($id, $storeId, $owner, $token, $action) {
        $field = $this->action($action);
        return $this->mutate($id,$storeId,$owner,$token,function ($row) use ($field,$action) {
            $allowed = ($action === 'bank' && $row['order_state'] === 'done')
                || (in_array($action,['history','clear'],true) && $row['bank_state'] === 'failed');
            if (!$allowed || $row[$field] !== 'pending') throw new JetSubmissionStorageException('Action cannot be skipped');
            return [$field=>'skipped'];
        });
    }

    public function recordOrder($id, $storeId, $owner, $token, $orderId) {
        $orderId = $this->orderId($orderId);
        return $this->mutate($id,$storeId,$owner,$token,function ($row) use ($orderId) {
            if ($row['order_state'] !== 'started' || $row['native_order_id'] !== null || $row['family'] === 'checkout') throw new JetSubmissionStorageException('Order cannot be recorded');
            return ['native_order_id'=>$orderId,'order_state'=>'done'];
        });
    }

    public function complete($id, $storeId, $owner, $token, $code) {
        if (!in_array($code,['bank_sent','bank_failed','manual_registered'],true)) throw new InvalidArgumentException('Invalid result code');
        return $this->mutate($id,$storeId,$owner,$token,function ($row) use ($code) {
            $expected = ['bank_sent'=>'done','bank_failed'=>'failed','manual_registered'=>'skipped'];
            if ($row['bank_state'] !== $expected[$code] || $row['order_state'] !== 'done') throw new JetSubmissionStorageException('Primary result mismatch');
            foreach (self::ACTIONS as $action) {
                // A secondary notification/native hook uncertainty must not undo a
                // known primary result. Its action remains terminal and non-repeatable.
                $terminal = in_array($action, ['order','bank'], true) ? ['done','failed','skipped'] : self::TERMINAL;
                if (!in_array($row[$action.'_state'], $terminal,true)) throw new JetSubmissionStorageException('Submission has unresolved actions');
            }
            return ['state'=>'completed','result_code'=>$code];
        });
    }

    /** No automatic takeover, expiry or resend. A disconnected worker stays blocked. */
    public function uncertain($id, $storeId, $owner, $token) {
        return $this->mutate($id,$storeId,$owner,$token,function ($row) {
            $values = ['state'=>'uncertain','result_code'=>'outcome_unknown'];
            foreach (self::ACTIONS as $action) if ($row[$action.'_state'] === 'started') $values[$action.'_state'] = 'uncertain';
            return $values;
        });
    }
}
