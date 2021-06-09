<?php
namespace Framework\Auth;

use Framework\Schema\Factory;
use Framework\Schema\Schema;
use Framework\Schema\Query;
use Framework\Utils\Strings;

/**
 * The Auth Reset
 */
class Reset {

    private static $loaded = false;
    private static $schema = null;


    /**
     * Loads the Reset Schema
     * @return Schema
     */
    public static function getSchema(): Schema {
        if (!self::$loaded) {
            self::$loaded = false;
            self::$schema = Factory::getSchema("resets");
        }
        return self::$schema;
    }



    /**
     * Returns the Credential ID for the given Code
     * @param string $code
     * @return integer
     */
    public static function getCredentialID(string $code): int {
        $query = Query::create("code", "=", $code);
        return self::getSchema()->getValue($query, "CREDENTIAL_ID");
    }

    /**
     * Returns the Email for the given Code
     * @param string $code
     * @return integer
     */
    public static function getEmail(string $code): string {
        $query = Query::create("code", "=", $code);
        return self::getSchema()->getValue($query, "email");
    }

    /**
     * Returns true if the given code exists
     * @param string $code
     * @return boolean
     */
    public static function codeExists(string $code): bool {
        $query = Query::create("code", "=", $code);
        return self::getSchema()->exists($query);
    }



    /**
     * Creates and saves a recover code for the given Credential
     * @param integer $credentialID Optional.
     * @param string  $email        Optional.
     * @return string
     */
    public static function create(int $credentialID = 0, string $email = ""): string {
        $code = Strings::randomCode(6, "ud");
        self::getSchema()->replace([
            "CREDENTIAL_ID" => $credentialID,
            "email"         => $email,
            "code"          => $code,
            "time"          => time(),
        ]);
        return $code;
    }

    /**
     * Deletes the reset data for the given Credential
     * @param integer $credentialID Optional.
     * @param string  $email        Optional.
     * @return boolean
     */
    public static function delete(int $credentialID = 0, string $email = ""): bool {
        $query = Query::createIf("CREDENTIAL_ID", "=", $credentialID);
        $query->addIf("email", "=", $email);
        return self::getSchema()->remove($query);
    }

    /**
     * Deletes the old reset data for all the Credentials
     * @return boolean
     */
    public static function deleteOld(): bool {
        $query = Query::create("time", "<", time() - 900);
        return self::getSchema()->remove($query);
    }
}
