<?php
namespace Framework\Log;

use Framework\Request;
use Framework\Auth\Auth;
use Framework\Schema\Factory;
use Framework\Schema\Schema;
use Framework\Schema\Query;
use Framework\Utils\Arrays;
use Framework\Utils\JSON;
use Framework\Utils\Server;

/**
 * The Actions Log
 */
class ActionLog {

    private static bool    $loaded      = false;
    private static ?Schema $logIDs      = null;
    private static ?Schema $logSessions = null;
    private static ?Schema $logActions  = null;


    /**
     * Loads the Action Schemas
     * @return boolean
     */
    public static function load(): bool {
        if (self::$loaded) {
            return false;
        }
        self::$logIDs      = Factory::getSchema("logIDs");
        self::$logSessions = Factory::getSchema("logSessions");
        self::$logActions  = Factory::getSchema("logActions");
        return true;
    }

    /**
     * Loads the IDs Schemas
     * @return Schema
     */
    public static function getIDsSchema(): Schema {
        self::load();
        return self::$logIDs;
    }

    /**
     * Loads the Sessions Schemas
     * @return Schema
     */
    public static function getSessionsSchema(): Schema {
        self::load();
        return self::$logSessions;
    }

    /**
     * Loads the Actions Schemas
     * @return Schema
     */
    public static function getActionsSchema(): Schema {
        self::load();
        return self::$logActions;
    }



    /**
     * Returns the Filter Query
     * @param Request $filters
     * @param array{} $mappings Optional.
     * @return Query
     */
    private static function getFilterQuery(Request $filters, array $mappings = []): Query {
        $query = new Query();
        $query->addIf("CREDENTIAL_ID", "=", $filters->credentialID);
        foreach ($mappings as $key => $value) {
            $query->addIf($value, "=", $filters->get($key));
        }

        $query->addIf("time", ">", $filters->fromTime);
        $query->addIf("time", "<", $filters->toTime);
        return $query;
    }

    /**
     * Returns all the Actions Log filtered by the given filters
     * @param Request $filters
     * @param Request $sort
     * @param array{} $mappings Optional.
     * @return array{}[]
     */
    public static function filter(Request $filters, Request $sort, array $mappings = []): array {
        $query = self::getFilterQuery($filters, $mappings);
        $query->orderBy("time", false);
        $query->paginate($sort->page, $sort->amount);
        return self::request($query);
    }

    /**
     * Returns the Total Actions Log with the given Filters
     * @param Request $filters
     * @param array{} $mappings Optional.
     * @return integer
     */
    public static function getTotal(Request $filters, array $mappings = []): int {
        $query = self::getFilterQuery($filters, $mappings);
        return self::getSessionsSchema()->getTotal($query);
    }

    /**
     * Returns the Actions Log using the given Query
     * @param Query $query
     * @return array{}[]
     */
    private static function request(Query $query): array {
        $sessionIDs = self::getSessionsSchema()->getColumn($query, "SESSION_ID");
        $querySess  = Query::create("SESSION_ID", "IN", $sessionIDs)->orderBy("time", false);
        $queryActs  = Query::create("SESSION_ID", "IN", $sessionIDs)->orderBy("time", true);
        $actions    = [];
        $result     = [];

        if (empty($sessionIDs)) {
            return [];
        }

        $request = self::getActionsSchema()->getMap($queryActs);
        foreach ($request as $row) {
            if (empty($actions[$row["sessionID"]])) {
                $actions[$row["sessionID"]] = [];
            }
            $actions[$row["sessionID"]][] = [
                "time"    => $row["time"],
                "action"  => $row["action"],
                "section" => $row["section"],
                "dataID"  => !empty($row["dataID"]) ? JSON::decode($row["dataID"]) : "",
            ];
        }

        $request = self::getSessionsSchema()->getMap($querySess);
        foreach ($request as $row) {
            $result[] = [
                "sessionID"      => $row["sessionID"],
                "credentialID"   => $row["credentialID"],
                "credentialName" => $row["credentialName"],
                "time"           => $row["time"],
                "ip"             => $row["ip"],
                "userAgent"      => $row["userAgent"],
                "actions"        => !empty($actions[$row["sessionID"]]) ? $actions[$row["sessionID"]] : [],
            ];
        }

        return $result;
    }



    /**
     * Starts a Log Session
     * @param integer $credentialID
     * @param boolean $destroy      Optional.
     * @return boolean
     */
    public static function startSession(int $credentialID, bool $destroy = false): bool {
        $sessionID = self::getSessionID();

        if ($destroy || empty($sessionID)) {
            $sessionID = self::getSessionsSchema()->create([
                "CREDENTIAL_ID" => $credentialID,
                "USER_ID"       => Auth::getUserID(),
                "ip"            => Server::getIP(),
                "userAgent"     => Server::getUserAgent(),
                "time"          => time(),
            ]);
            self::setSessionID($sessionID);
            return true;
        }
        return false;
    }

    /**
     * Ends the Log Session
     * @return boolean
     */
    public static function endSession(): bool {
        return self::setSessionID();
    }



    /**
     * Logs the given Action
     * @param integer       $action
     * @param integer       $section Optional.
     * @param mixed|integer $dataID  Optional.
     * @return boolean
     */
    public static function add(int $action, int $section = 0, mixed $dataID = 0): bool {
        $sessionID = self::getSessionID();
        if (empty($sessionID)) {
            return false;
        }
        $dataID = Arrays::toArray($dataID);
        foreach ($dataID as $index => $value) {
            $dataID[$index] = (int)$value;
        }

        self::getActionsSchema()->create([
            "SESSION_ID"    => $sessionID,
            "CREDENTIAL_ID" => Auth::getID(),
            "USER_ID"       => Auth::getUserID(),
            "action"        => $action,
            "section"       => $section,
            "dataID"        => JSON::encode($dataID),
            "time"          => time(),
        ]);
        return true;
    }

    /**
     * Returns the Session ID for the current Credential
     * @return integer
     */
    public static function getSessionID(): int {
        $query = Query::create("CREDENTIAL_ID", "=", Auth::getID());
        return (int)self::getIDsSchema()->getValue($query, "SESSION_ID");
    }

    /**
     * Sets the given Session ID for the current Credential
     * @param integer $sessionID Optional.
     * @return boolean
     */
    public static function setSessionID(int $sessionID = 0): bool {
        $result = self::getIDsSchema()->replace([
            "CREDENTIAL_ID" => Auth::getID(),
            "SESSION_ID"    => $sessionID,
        ]);
        return $result > 0;
    }
}
