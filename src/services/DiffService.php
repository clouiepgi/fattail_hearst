<?php
namespace CentralDesktop\FatTail\Services;

/**
 * Class DiffService responsible for keeping track of
 * which records have changed by using checksums (php crc32)
 * @package CentralDesktop\FatTail\Services
 */
class DiffService {

    protected $diff_file = null;
    const ORDERS_TYPE = 'orders';
    const DROPS_TYPE = 'drops';
    const SEPARATOR = ':';
    // Array holding id => checksum (hex) pairs
    protected $loaded_checksums = [
        self::ORDERS_TYPE => [],
        self::DROPS_TYPE => [],
    ];
    protected $checksums = [
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
                        // Break the id => checksum pair and load it into memory
                        $line = explode(self::SEPARATOR, $line);
                        $this->loaded_checksums[$type][$line[0]] = $line[1];
                    }
                }
                else {
                    // Switching type
                    $type = $line;
                }
            }
            fclose($file);
        }
    }

    /**
     * Gets a checksum.
     *
     * @param $type
     * @param $id
     * @return mixed
     */
    public
    function get_loaded_checksum($type, $id) {

        if (!isset($this->checksum[$type][$id])) {
            return null;
        }

        return $this->checksum[$type][$id];
    }

    /**
     * Generates a checksum
     * @param array $values
     * @return string
     */
    public
    function generate_checksum(array $values) {
        $joined = join('', $values);

        return sprintf("%x", crc32($joined));
    }

    /**
     * Adds a checksum to the checksum store.
     *
     * @param $type
     * @param array $value
     */
    public
    function add_item($type, $id, array $values = []) {

        if (!isset($this->checksums[$id])) {
            $checksum = $this->generate_checksum($values);
            $this->checksums[$type][$id] = $checksum;
        }
    }

    /**
     * Save the new checksums to the diff file.
     */
    public
    function save_to_file() {

        $file = fopen($this->diff_file, 'w');
        if ($file) {
            // Write orders
            fwrite($file, self::ORDERS_TYPE . "\n");
            foreach ($this->checksums[self::ORDERS_TYPE] as $id => $checksum) {
                fwrite($file, join(self::SEPARATOR, [$id, $checksum]) . "\n");
            }

            // Write drops
            fwrite($file, self::DROPS_TYPE . "\n");
            foreach ($this->checksums[self::DROPS_TYPE] as $id => $checksum) {
                fwrite($file, join(self::SEPARATOR, [$id, $checksum]) . "\n");
            }
            fclose($file);
        }
    }


}