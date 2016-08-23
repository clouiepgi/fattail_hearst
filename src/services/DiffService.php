<?php
namespace CentralDesktop\FatTail\Services;

/**
 * Class DiffService responsible for keeping track of
 * which records have changed by using md5 hashes
 * @package CentralDesktop\FatTail\Services
 */
class DiffService {

    protected $diff_file = null;
    const ORDERS_TYPE = 'orders';
    const DROPS_TYPE = 'drops';
    const SEPARATOR = ':';

    // Array holding id => md5 pairs
    protected $loaded_hashes = [
        self::ORDERS_TYPE => [],
        self::DROPS_TYPE => [],
    ];
    protected $hashes = [
        self::ORDERS_TYPE => [],
        self::DROPS_TYPE => [],
    ];

    public
    function __construct($diff_dir, $diff_file) {
        $this->diff_file = $this->init($diff_dir, $diff_file);
    }

    /**
     * Ensures the diff file exists.
     *
     * @param $diff_dir
     * @param $diff_file
     * @return string
     */
    protected
    function init($diff_dir, $diff_file) {

        if (!is_dir($diff_dir)) {
            mkdir($diff_dir);
        }

        $diff_file_path = "$diff_dir/$diff_file";

        if (!file_exists($diff_file_path)) {
            touch($diff_file_path);
        }

        return $diff_file_path;
    }

    /**
     * Loads the diff file.
     */
    public
    function load_from_file() {

        $file = fopen($this->diff_file, 'r');
        if ($file) {
            $type = null;
            while (($line = fgets($file)) !== false) {

                // Read file line by line
                if (strpos($line, self::SEPARATOR) > 0) {

                    if (!is_null($type)) {
                        // Break the id => hashch pair and load it into memory
                        $line = explode(self::SEPARATOR, $line);
                        $line = array_map(function($item) { return trim($item); }, $line);
                        $this->loaded_hashes[$type][$line[0]] = $line[1];
                    }
                }
                else {
                    // Switching type
                    $type = trim($line);
                }
            }
            fclose($file);
        }
    }

    /**
     * Gets a hash.
     *
     * @param $type
     * @param $id
     * @return mixed
     */
    public
    function get_loaded_hash($type, $id) {

        if (!isset($this->loaded_hashes[$type][$id])) {
            return null;
        }

        return $this->loaded_hashes[$type][$id];
    }

    /**
     * Generates a hash
     * @param array $values
     * @return string
     */
    public
    function generate_hash(array $values) {
        return md5(join('', $values));
    }

    /**
     * Determines if an item is different (it's new or there are changes).
     *
     * @param $type string the item type
     * @param $id integer the item id
     * @param $values array the values to check
     * @return bool true if is different, false otherwise
     */
    public
    function is_different($type, $id, array $values = []) {
        $old_hash = $this->get_loaded_hash($type, $id);
        $hash = $this->generate_hash($values);
        return is_null($old_hash) || $old_hash !== $hash;
    }

    /**
     * Adds a hash to the hash store.
     *
     * @param $type string
     * @param $id integer
     * @param $values array
     */
    public
    function add_item($type, $id, array $values = []) {

        if (!isset($this->hashes[$type][$id])) {
            $hash = $this->generate_hash($values);
            $this->hashes[$type][$id] = $this->loaded_hashes[$type][$id] = $hash;

        }
    }

    /**
     * Save the new hashes to the diff file.
     */
    public
    function save_to_file() {

        $file = fopen($this->diff_file, 'w');
        if ($file) {
            // Write orders
            fwrite($file, self::ORDERS_TYPE . "\n");
            foreach ($this->hashes[self::ORDERS_TYPE] as $id => $hash) {
                fwrite($file, join(self::SEPARATOR, [$id, $hash]) . "\n");
            }

            // Write drops
            fwrite($file, self::DROPS_TYPE . "\n");
            foreach ($this->hashes[self::DROPS_TYPE] as $id => $hash) {
                fwrite($file, join(self::SEPARATOR, [$id, $hash]) . "\n");
            }
            fclose($file);
        }
    }
}