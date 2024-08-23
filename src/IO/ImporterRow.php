<?php
namespace Framework\IO;

use Framework\Utils\Strings;

use ArrayAccess;

/**
 * The Importer Row
 */
class ImporterRow implements ArrayAccess {

    /** @var array{} */
    private array $fields;

    /** @var array{} */
    private array $columns;



    /**
     * Creates a new ImporterRow instance
     * @param array{} $fields
     * @param array{} $columns
     */
    public function __construct(array $fields, array $columns) {
        $this->fields  = $fields;
        $this->columns = $columns;
    }

    /**
     * Returns all the Fields
     * @return array{}
     */
    public function toArray(): array {
        return $this->fields;
    }



    /**
     * Returns the Field Value for the given Key
     * @param string  $key
     * @param boolean $splitResult Optional.
     * @return string[]|string
     */
    private function getValue(string $key, bool $splitResult = false): array|string {
        $index  = !empty($this->columns[$key]) ? $this->columns[$key] - 1 : -1;
        $result = "";

        if ($index < 0 || !isset($this->fields[$index])) {
            $result = "";
        } elseif (!empty($this->fields[$index]) || $this->fields[$index] === "0") {
            $result = $this->fields[$index];
        }
        return $splitResult ? Strings::split($result, ",", true, true) : $result;
    }

    /**
     * Returns the Field Value for the given Key as a String
     * @param string $key
     * @return string
     */
    public function getString(string $key): string {
        return (string)$this->getValue($key);
    }

    /**
     * Returns the Field Value for the given Key as an Integer
     * @param string $key
     * @return integer
     */
    public function getInt(string $key): int {
        return (int)$this->getValue($key);
    }

    /**
     * Returns the Field Value for the given Key as a Float
     * @param string $key
     * @return float
     */
    public function getFloat(string $key): float {
        return (float)$this->getValue($key);
    }

    /**
     * Returns the Field Value for the given Key as a List of Strings
     * @param string $key
     * @return string[]
     */
    public function getList(string $key): array {
        return $this->getValue($key, true);
    }



    /**
     * Implements the Array Access Interface
     * @param mixed $key
     * @return mixed
     */
    public function offsetGet(mixed $key): mixed {
        return $this->fields[$key];
    }

    /**
     * Implements the Array Access Interface
     * @param mixed $key
     * @param mixed $value
     * @return void
     */
    public function offsetSet(mixed $key, mixed $value): void {
        $this->fields[$key] = $value;
    }

    /**
     * Implements the Array Access Interface
     * @param mixed $key
     * @return boolean
     */
    public function offsetExists(mixed $key): bool {
        return array_key_exists($key, $this->fields);
    }

    /**
     * Implements the Array Access Interface
     * @param mixed $key
     * @return void
     */
    public function offsetUnset(mixed $key): void {
        unset($this->fields[$key]);
    }
}
