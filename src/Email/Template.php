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

    /**
     * Loads the Email Templates Schema
     * @return Schema
     */
    public static function schema(): Schema {
        return Factory::getSchema("emailTemplates");
    }



    /**
     * Returns an Email Template with the given Code
     * @param string $templateCode
     * @return Model
     */
    public static function getOne(string $templateCode): Model {
        $query = Query::create("templateCode", "=", $templateCode);
        return self::schema()->getOne($query);
    }

    /**
     * Returns true if there is an Email Template with the given Code
     * @param string $templateCode
     * @return boolean
     */
    public static function exists(string $templateCode): bool {
        $query = Query::create("templateCode", "=", $templateCode);
        return self::schema()->exists($query);
    }

    /**
     * Returns all the Email Templates
     * @param Request|null $request Optional.
     * @return array{}[]
     */
    public static function getAll(?Request $request = null): array {
        return self::schema()->getAll(null, $request);
    }

    /**
     * Returns the total amount of Email Templates
     * @return integer
     */
    public static function getTotal(): int {
        return self::schema()->getTotal();
    }

    /**
     * Edits the given Email Template
     * @param string  $templateCode
     * @param Request $request
     * @return boolean
     */
    public static function edit(string $templateCode, Request $request): bool {
        $query = Query::create("templateCode", "=", $templateCode);
        return self::schema()->edit($query, $request);
    }



    /**
     * Renders the Email Template message with Mustache
     * @param string  $message
     * @param array{} $data    Optional.
     * @return string
     */
    public static function render(string $message, array $data = []): string {
        $html   = !Strings::contains($message, "</p>\n\n<p>") ? Strings::toHtml($message) : $message;
        $result = Mustache::render($html, $data);

        $result = Strings::replace($result, "<p></p>", "");
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
     * @return boolean
     */
    public static function migrate(Database $db, bool $recreate = false, bool $sandbox = false): bool {
        if (!$db->hasTable("email_templates")) {
            return false;
        }

        $request  = $db->getAll("email_templates");
        $emails   = Framework::loadData(Framework::EmailData);
        $siteName = Config::get("name");
        $sendAs   = Config::get("smtpEmail");

        if (empty($emails)) {
            return false;
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
                    "type"         => !empty($data["type"]) ? $data["type"] : "",
                    "sendAs"       => !empty($data["sendAs"]) ? $data["sendAs"] : $sendAs,
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

        return true;
    }
}
