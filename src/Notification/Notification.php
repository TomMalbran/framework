<?php
namespace Framework\Notification;

use Framework\System\ConfigCode;
use Framework\Provider\Curl;
use Framework\File\FilePath;
use Framework\Utils\Strings;

/**
 * The Notification Provider
 */
class Notification {

    public const BaseUrl = "https://api.onesignal.com";

    private static bool   $loaded   = false;
    private static bool   $isActive = false;
    private static string $icon     = "";
    private static string $appID    = "";
    private static string $restKey  = "";


    /**
     * Creates the Notification Provider
     * @return boolean
     */
    public static function load(): bool {
        if (self::$loaded) {
            return false;
        }

        self::$loaded   = true;
        self::$isActive = ConfigCode::getBoolean("notificationIsActive");
        self::$icon     = ConfigCode::getString("notificationIcon");

        self::$appID    = ConfigCode::getString("onesignalAppId");
        self::$restKey  = ConfigCode::getString("onesignalRestKey");
        return true;
    }



    /**
     * Send to All
     * @param string  $title
     * @param string  $body
     * @param string  $url
     * @param string  $dataType
     * @param integer $dataID
     * @return string|null
     */
    public static function sendToAll(string $title, string $body, string $url, string $dataType, int $dataID): ?string {
        return self::send($title, $body, $dataType, $url, $dataID, [
            "included_segments" => [ "All" ],
        ]);
    }

    /**
     * Send to Some
     * @param string   $title
     * @param string   $body
     * @param string   $url
     * @param string   $dataType
     * @param integer  $dataID
     * @param string[] $playerIDs
     * @return string|null
     */
    public static function sendToSome(string $title, string $body, string $url, string $dataType, int $dataID, array $playerIDs): ?string {
        if (empty($playerIDs)) {
            return null;
        }
        return self::send($title, $body, $url, $dataType, $dataID, [
            "include_player_ids" => $playerIDs,
        ]);
    }

    /**
     * Send to Some
     * @param string  $title
     * @param string  $body
     * @param string  $url
     * @param string  $dataType
     * @param integer $dataID
     * @param string  $playerID
     * @return string|null
     */
    public static function sendToOne(string $title, string $body, string $url, string $dataType, int $dataID, string $playerID): ?string {
        return self::send($title, $body, $url, $dataType, $dataID, [
            "include_player_ids" => [ $playerID ],
        ]);
    }

    /**
     * Sends the Notification
     * @param string  $title
     * @param string  $body
     * @param string  $url
     * @param string  $dataType
     * @param integer $dataID
     * @param array{} $params
     * @return string|null
     */
    private static function send(string $title, string $body, string $url, string $dataType, int $dataID, array $params): ?string {
        self::load();
        if (!self::$isActive) {
            return null;
        }

        $icon = "";
        if (!empty(self::$icon)) {
            $icon = FilePath::getInternalPath(self::$icon);
        }

        $fullUrl = $url;
        if (!Strings::startsWith($url, "http")) {
            $fullUrl = ConfigCode::getUrl("url", $url);
        }

        $data = [
            "app_id"         => self::$appID,
            "headings"       => [ "en" => $title ],
            "contents"       => [ "en" => $body  ],
            "url"            => $fullUrl,
            "large_icon"     => $icon,
            "ios_badgeType"  => "Increase",
            "ios_badgeCount" => 1,
            "data"           => [
                "type"   => $dataType,
                "dataID" => $dataID,
            ],
        ] + $params;

        $headers = [
            "Content-Type"  => "application/json; charset=utf-8",
            "Authorization" => "Basic " . self::$restKey,
        ];
        $response = Curl::execute("POST", self::BaseUrl . "/notifications", $data, $headers, jsonBody: true);

        if (empty($response["id"])) {
            return null;
        }
        return $response["id"];
    }
}
