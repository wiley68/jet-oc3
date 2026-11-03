<?php
// POST boundary from OpenCart 3.0.3.8 system/library/request.php (Request::clean).
// https://github.com/opencart/opencart/blob/3.0.3.8/upload/system/library/request.php
// Test-only: no globals, native source, configuration or persistent state are changed.
class OpenCartProductRequestDouble {
    public $post;

    public function __construct($wire_post) {
        $this->post = $this->clean($wire_post);
    }

    public function clean($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                unset($data[$key]);
                $data[$this->clean($key)] = $this->clean($value);
            }
        } else {
            $data = htmlspecialchars($data, ENT_COMPAT, 'UTF-8');
        }

        return $data;
    }
}
