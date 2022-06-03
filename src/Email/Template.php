<?php
namespace Framework\Email;

use Framework\Framework;
use Framework\Request;
use Framework\Config\Config;
use Framework\Provider\Mustache;
use Framework\Schema\Factory;
use Framework\Schema\Schema;
use Framework\Schema\Database;
use Framework\Schema\Model;
use Framework\Schema\Query;
use Framework\Utils\Strings;

/**
 * The Email Templates
 */
class Template {

    private static $loaded = false;
    private static $schema = null;


    /**
     * Loads the Email Templates Schema
     * @return Schema
     */
    public static function getSchema(): Schema {
        if (!self::$loaded) {
            self::$loaded = false;
            self::$schema = Factory::getSchema("emailTemplates");
        }
        return self::$schema;
    }



    /**
     * Returns an Email Template with the given Code
     * @param string $templateCode
     * @return Model
     */
    public static function getOne(string $templateCode): Model {
        $query = Query::create("templateCode", "=", $templateCode);
        return self::getSchema()->getOne($query);
    }

    /**
     * Returns true if there is an Email Template with the given Code
     * @param string $templateCode
     * @return boolean
     */
    public static function exists(string $templateCode): bool {
        $query = Query::create("templateCode", "=", $templateCode);
        return self::getSchema()->exists($query);
    }

    /**
     * Returns all the Email Templates
     * @param Request $request
     * @return array
     */
    public static function getAll(Request $request): array {
        return self::getSchema()->getAll(null, $request);
    }

    /**
     * Returns the total amount of Email Templates
     * @return integer
     */
    public static function getTotal(): int {
        return self::getSchema()->getTotal();
    }

    /**
     * Edits the given Email Template
     * @param string  $templateCode
     * @param Request $request
     * @return boolean
     */
    public static function edit(string $templateCode, Request $request): bool {
        $query = Query::create("templateCode", "=", $templateCode);
        return self::getSchema()->edit($query, $request);
    }



    /**
     * Renders the Email Template message with Mustache
     * @param string $message
     * @param array  $data    Optional.
     * @return string
     */
    public static function render(string $message, array $data = []): string {
        $result = Mustache::render(Strings::toHtml($message), $data);
        while (Strings::contains($result, "<br><br><br>")) {
            $result = Strings::replace($result, "<br><br><br>", "<br><br>");
        }
        return $result;
    }

    /**
     * Migrates the Email Templates
     * @param Database $db
     * @param boolean  $recreate Optional.
     * @param boolean  $sandbox  Optional.
     * @return void
     */
    public static function migrate(Database $db, bool $recreate = false, bool $sandbox = false): void {
        if (!$db->hasTable("email_templates")) {
            return;
        }

        $request  = $db->getAll("email_templates");
        $emails   = Framework::loadData(Framework::EmailData);
        $siteName = Config::get("name");
        $sendAs   = Config::get("smtpEmail");

        if (empty($emails)) {
            return;
        }

        $adds     = [];
        $deletes  = [];
        $codes    = [];
        $position = $recreate ? 0 : count($request);

        // Adds the Email Templates
        foreach ($emails as $templateCode => $data) {
            $found = false;
            if (!$recreate) {
                foreach ($request as $row) {
                    if ($row["templateCode"] == $templateCode) {
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found) {
                $position += 1;
                $message   = Strings::join($data["message"], "\n\n");
                $codes[]   = $templateCode;
                $adds[]    = [
                    "templateCode" => $templateCode,
                    "description"  => $data["description"],
                    "sendAs"       => $sendAs,
                    "sendName"     => $siteName,
                    "sendTo"       => !empty($data["sendTo"]) ? "\"{$data["sendTo"]}\"" : "",
                    "subject"      => ($sandbox ? "PRUEBA - " : "") . Strings::replace($data["subject"], "[site]", $siteName),
                    "message"      => Strings::replace($message, "[site]", $siteName),
                    "position"     => $position,
                ];
            }
        }

        // Removes the Email Templates
        if (!$recreate) {
            foreach ($request as $row) {
                $found = false;
                foreach (array_keys($emails) as $templateCode) {
                    if ($row["templateCode"] == $templateCode) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $deletes[] = $row["templateCode"];
                }
            }
        }

        // Process the SQL
        if ($recreate) {
            print("<br>Removed <i>" . count($request) . " emails</i><br>");
            $db->truncate("email_templates");
        }
        if (!empty($adds)) {
            print("<br>Added <i>" . count($adds) . " emails</i><br>");
            print(Strings::join($codes, ", ") . "<br>");
            $db->batch("email_templates", $adds);
        }
        if (!empty($deletes)) {
            print("<br>Deleted <i>" . count($deletes) . " emails</i><br>");
            print(Strings::join($deletes, ", ") . "<br>");
            foreach ($deletes as $templateCode) {
                $query = Query::create("templateCode", "=", $templateCode);
                $db->delete("email_templates", $query);
            }
        }
        if (empty($adds) && empty($deletes)) {
            print("<br>No <i>emails</i> added or deleted <br>");
        }
    }
}
