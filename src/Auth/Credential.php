<?php
namespace Framework\Auth;

use Framework\Request;
use Framework\NLS\NLS;
use Framework\File\Path;
use Framework\Schema\Factory;
use Framework\Schema\Schema;
use Framework\Schema\Model;
use Framework\Schema\Query;
use Framework\Utils\Arrays;
use Framework\Utils\Strings;
use Framework\Utils\Status;
use Framework\Utils\Utils;

use ArrayAccess;

/**
 * The Auth Credential
 */
class Credential {

    /**
     * Loads the Credential Schema
     * @return Schema
     */
    private static function schema(): Schema {
        return Factory::getSchema("credentials");
    }

    /**
     * Creates a Level Query
     * @param integer[]|integer    $level
     * @param string[]|string|null $filter Optional.
     * @param mixed|integer        $value  Optional.
     * @return Query|null
     */
    private static function createLevelQuery(array|int $level, array|string $filter = null, mixed $value = 1): ?Query {
        $levels = Arrays::toArray($level);
        if (empty($levels)) {
            return null;
        }
        $query = Query::create("level", "IN", $levels);
        if (!empty($filter)) {
            $filters = Arrays::toArray($filter);
            foreach ($filters as $key) {
                $query->add($key, "=", $value);
            }
        }
        return $query;
    }



    /**
     * Returns the Credential with the given ID
     * @param integer $credentialID
     * @param boolean $complete     Optional.
     * @return Model
     */
    public static function getOne(int $credentialID, bool $complete = false): Model {
        $query = Query::create("CREDENTIAL_ID", "=", $credentialID);
        return self::requestOne($query, $complete);
    }

    /**
     * Returns the Name of the Credential with the given ID
     * @param integer $credentialID
     * @return string
     */
    public static function getOneName(int $credentialID): string {
        $query      = Query::create("CREDENTIAL_ID", "=", $credentialID);
        $credential = self::requestOne($query);
        return self::getName($credential);
    }

    /**
     * Returns the Credential with the given Email
     * @param string  $email
     * @param boolean $complete Optional.
     * @return Model
     */
    public static function getByEmail(string $email, bool $complete = true): Model {
        $query = Query::create("email", "=", $email);
        return self::requestOne($query, $complete);
    }

    /**
     * Returns the Credential with the given Access Token
     * @param string  $accessToken
     * @param boolean $complete    Optional.
     * @return Model
     */
    public static function getByAccessToken(string $accessToken, bool $complete = true): Model {
        $query = Query::create("accessToken", "=", $accessToken);
        $query->add("tokenExpiration", ">", time());
        return self::requestOne($query, $complete);
    }



    /**
     * Returns true if there is an Credential with the given ID
     * @param integer $credentialID
     * @return boolean
     */
    public static function exists(int $credentialID): bool {
        return self::schema()->exists($credentialID);
    }

    /**
     * Returns true if there is an Credential with the given ID and Level(s)
     * @param integer              $credentialID
     * @param integer[]|integer    $level
     * @param string[]|string|null $filter       Optional.
     * @param mixed|integer        $value        Optional.
     * @return boolean
     */
    public static function existsWithLevel(int $credentialID, array|int $level, array|string $filter = null, mixed $value = 1): bool {
        $query = self::createLevelQuery($level, $filter, $value);
        if (empty($query)) {
            return false;
        }
        $query->add("CREDENTIAL_ID", "=", $credentialID);
        return self::schema()->exists($query);
    }

    /**
     * Returns true if there is an Credential with the given Email
     * @param string            $email
     * @param integer           $skipID Optional.
     * @param integer[]|integer $level  Optional.
     * @return boolean
     */
    public static function emailExists(string $email, int $skipID = 0, array|int $level = 0): bool {
        $query = empty($level) ? new Query() : self::createLevelQuery($level);
        $query->add("email", "=", $email);
        $query->addIf("CREDENTIAL_ID", "<>", $skipID);
        return self::schema()->exists($query);
    }

    /**
     * Returns true if there is an Credential with the given Value for the given Field
     * @param string  $field
     * @param mixed   $value
     * @param integer $skipID Optional.
     * @return boolean
     */
    public static function fieldExists(string $field, mixed $value, int $skipID = 0): bool {
        $query = Query::create($field, "=", $value);
        $query->addIf("CREDENTIAL_ID", "<>", $skipID);
        return self::schema()->exists($query);
    }



    /**
     * Returns all the Credentials
     * @param Request|null $sort  Optional.
     * @param Query|null   $query Optional.
     * @return array{}[]
     */
    public static function getAll(?Request $sort = null, ?Query $query = null): array {
        return self::request($query, false, $sort);
    }

    /**
     * Returns all the Credentials for the given Level(s)
     * @param integer[]|integer $level
     * @param Request|null      $sort  Optional.
     * @return array{}[]
     */
    public static function getAllForLevel(array|int $level, ?Request $sort = null): array {
        $query = self::createLevelQuery($level);
        if (empty($query)) {
            return [];
        }
        return self::request($query, false, $sort);
    }

    /**
     * Returns all the Credentials for the given IDs
     * @param integer[]    $credentialIDs
     * @param Request|null $sort          Optional.
     * @return array{}[]
     */
    public static function getAllWithIDs(array $credentialIDs, ?Request $sort = null): array {
        if (empty($credentialIDs)) {
            return [];
        }
        $query = Query::create("CREDENTIAL_ID", "IN", $credentialIDs);
        return self::request($query, false, $sort);
    }

    /**
     * Returns all the Credentials for the given Level(s) and filter
     * @param integer[]|integer $level
     * @param string            $filter
     * @param mixed|integer     $value  Optional.
     * @param Request|null      $sort   Optional.
     * @return array{}[]
     */
    public static function getAllWithFilter(array|int $level, string $filter, mixed $value = 1, ?Request $sort = null): array {
        $query = self::createLevelQuery($level, $filter, $value);
        if (empty($query)) {
            return [];
        }
        return self::request($query, false, $sort);
    }

    /**
     * Returns all the Credentials for the given Level(s)
     * @param integer[]|integer $level
     * @param Request|null      $sort  Optional.
     * @return array{}[]
     */
    public static function getActiveForLevel(array|int $level, ?Request $sort = null): array {
        $query = self::createLevelQuery($level);
        if (empty($query)) {
            return [];
        }
        $query->add("status", "=", Status::Active());
        return self::request($query, false, $sort);
    }

    /**
     * Returns the latest Credentials for the given Level(s)
     * @param integer[]|integer $level
     * @param integer           $amount
     * @param string|null       $filter Optional.
     * @param mixed|integer     $value  Optional.
     * @return array{}[]
     */
    public static function getLatestForLevel(array|int $level, int $amount, ?string $filter = null, mixed $value = 1): array {
        $query = self::createLevelQuery($level, $filter, $value);
        if (empty($query)) {
            return [];
        }
        $query->orderBy("createdTime", false);
        $query->limit($amount);
        return self::request($query, false);
    }

    /**
     * Returns the create times for all the Credentials with the given level
     * @param integer $fromTime
     * @param integer $level    Optional.
     * @return array{}[]
     */
    public static function getAllCreateTimes(int $fromTime, int $level = 0): array {
        $query   = Query::create("createdTime", ">=", $fromTime);
        $query->addIf("level", "=", $level);

        $request = self::schema()->getAll($query);
        $result  = [];

        foreach ($request as $row) {
            $result[$row["credentialID"]] = $row["createdTime"];
        }
        return $result;
    }

    /**
     * Returns the total amount of Credentials
     * @param Query|null $query Optional.
     * @return integer
     */
    public static function getTotal(?Query $query = null): int {
        return self::schema()->getTotal($query);
    }

    /**
     * Returns the total amount of Credentials for the given Level(s)
     * @param integer[]|integer $level
     * @param string|null       $filter Optional.
     * @param mixed|integer     $value  Optional.
     * @return integer
     */
    public static function getTotalForLevel(array|int $level, ?string $filter = null, mixed $value = 1): int {
        $query = self::createLevelQuery($level, $filter, $value);
        if (empty($query)) {
            return 0;
        }
        return self::schema()->getTotal($query);
    }

    /**
     * Requests data to the database
     * @param Query|null   $query    Optional.
     * @param boolean      $complete Optional.
     * @param Request|null $sort     Optional.
     * @return array{}[]
     */
    private static function request(?Query $query = null, bool $complete = false, ?Request $sort = null): array {
        $request = self::schema()->getAll($query, $sort);
        $result  = [];

        foreach ($request as $row) {
            $fields = $row;
            $fields["credentialName"] = self::getName($row);

            if (!empty($row["avatar"]) && Path::exists("avatars", $row["avatar"])) {
                $fields["avatarFile"] = $row["avatar"];
                $fields["avatar"]     = Path::getUrl("avatars", $row["avatar"]);
            }
            if (!$complete) {
                unset($fields["password"]);
                unset($fields["salt"]);
                unset($fields["accessToken"]);
                unset($fields["tokenExpiration"]);
            }
            $result[] = $fields;
        }
        return $result;
    }

    /**
     * Requests a single row from the database
     * @param Query|null $query    Optional.
     * @param boolean    $complete Optional.
     * @return Model
     */
    private static function requestOne(?Query $query = null, bool $complete = false): Model {
        $request = self::request($query, $complete);
        return self::schema()->getModel($request);
    }



    /**
     * Returns a select of all the Credentials
     * @return array{}[]
     */
    public static function getSelect(): array {
        return self::requestSelect();
    }

    /**
     * Returns a select of Credentials for the given Level(s)
     * @param integer[]|integer    $level
     * @param string[]|string|null $filter Optional.
     * @param mixed|integer        $value  Optional.
     * @return array{}[]
     */
    public static function getSelectForLevel(array|int $level, array|string $filter = null, mixed $value = 1): array {
        $query = self::createLevelQuery($level, $filter, $value);
        if (empty($query)) {
            return [];
        }
        $query->orderBy("level", false);
        return self::requestSelect($query);
    }

    /**
     * Returns a select of Credentials with the given IDs
     * @param integer[] $credentialIDs
     * @return array{}[]
     */
    public static function getSelectForIDs(array $credentialIDs): array {
        if (empty($credentialIDs)) {
            return [];
        }
        $query = Query::create("CREDENTIAL_ID", "IN", $credentialIDs);
        $query->orderBy("firstName", true);
        return self::requestSelect($query);
    }

    /**
     * Returns the Credentials that contains the text and the given Levels
     * @param string                 $text
     * @param integer                $amount       Optional.
     * @param integer[]|integer|null $level        Optional.
     * @param integer[]|integer|null $credentialID Optional.
     * @param boolean                $splitText    Optional.
     * @return array{}[]
     */
    public static function search(string $text, int $amount = 10, array|int $level = null, array|int $credentialID = null, bool $splitText = true): array {
        $query = Query::createSearch([ "firstName", "lastName", "email" ], $text, "LIKE", true, $splitText);
        $query->addIf("level",         "IN", Arrays::toArray($level),        $level !== null);
        $query->addIf("CREDENTIAL_ID", "IN", Arrays::toArray($credentialID), $credentialID !== null);
        $query->limit($amount);

        $request = self::schema()->getAll($query);
        $result  = [];

        foreach ($request as $row) {
            $result[] = [
                "id"    => $row["credentialID"],
                "title" => self::getName($row),
                "email" => $row["email"],
            ];
        }
        return $result;
    }

    /**
     * Returns a select of Credentials under the given conditions
     * @param Query|null $query Optional.
     * @return array{}[]
     */
    private static function requestSelect(?Query $query = null): array {
        $request = self::schema()->getAll($query);
        $result  = [];

        foreach ($request as $row) {
            $result[] = [
                "key"   => $row["credentialID"],
                "value" => self::getName($row),
            ];
        }
        return $result;
    }

    /**
     * Returns a list of emails of the Credentials with the given Levels
     * @param integer[]|integer    $level
     * @param string[]|string|null $filter Optional.
     * @param mixed|integer        $value  Optional.
     * @return array<string,string>
     */
    public static function getEmailsForLevel(array|int $level, array|string $filter = null, mixed $value = 1): array {
        $query = self::createLevelQuery($level, $filter, $value);
        if (empty($query)) {
            return [];
        }
        $request = self::schema()->getAll($query);
        return Arrays::createMap($request, "email", "language");
    }



    /**
     * Returns true if the given password is correct for the given Credential ID
     * @param Model|integer $credential
     * @param string        $password
     * @return boolean
     */
    public static function isPasswordCorrect(Model|int $credential, string $password): bool {
        if (!($credential instanceof Model)) {
            $credential = self::getOne($credential, true);
        }
        if ($credential->passExpiration > 0 && $credential->passExpiration < time()) {
            return false;
        }
        $hash = self::createHash($password, $credential->salt);
        return $hash["password"] == $credential->password;
    }

    /**
     * Returns true if the given Credential requires a password change
     * @param integer $credentialID Optional.
     * @param string  $email        Optional.
     * @return boolean
     */
    public static function reqPassChange(int $credentialID = 0, string $email = ""): bool {
        if (empty($credentialID) && empty($email)) {
            return false;
        }
        $query = new Query();
        $query->startOr();
        $query->addIf("CREDENTIAL_ID", "=", $credentialID);
        $query->addIf("email",         "=", $email);
        $query->endOr();
        return self::schema()->getValue($query, "reqPassChange") == 1;
    }



    /**
     * Creates a new Credential
     * @param Request|array{} $request
     * @param integer         $level
     * @param boolean|null    $reqPassChange Optional.
     * @return integer
     */
    public static function create(Request|array $request, int $level, ?bool $reqPassChange = null): int {
        $fields = self::getFields($request, $level, $reqPassChange);
        return self::schema()->create($request, $fields + [
            "lastLogin"    => time(),
            "currentLogin" => time(),
        ]);
    }

    /**
     * Edits the given Credential
     * @param integer         $credentialID
     * @param Request|array{} $request
     * @param integer         $level         Optional.
     * @param boolean|null    $reqPassChange Optional.
     * @param boolean         $skipEmpty     Optional.
     * @return boolean
     */
    public static function edit(int $credentialID, Request|array $request, int $level = 0, ?bool $reqPassChange = null, bool $skipEmpty = false): bool {
        $fields = self::getFields($request, $level, $reqPassChange);
        return self::schema()->edit($credentialID, $request, $fields, 0, $skipEmpty);
    }

    /**
     * Updates the given Credential
     * @param integer   $credentialID
     * @param array{}[] $fields
     * @return boolean
     */
    public static function update(int $credentialID, array $fields): bool {
        return self::schema()->edit($credentialID, $fields);
    }

    /**
     * Deletes the given Credential
     * @param integer $credentialID
     * @return boolean
     */
    public static function delete(int $credentialID): bool {
        return self::schema()->delete($credentialID);
    }

    /**
     * Destroys the Credential
     * @param integer $credentialID
     * @return boolean
     */
    public static function destroy(int $credentialID): bool {
        return self::schema()->edit($credentialID, [
            "currentUser"      => 0,
            "email"            => "mail@mail.com",
            "firstName"        => NLS::get("GENERAL_NAME"),
            "lastName"         => NLS::get("GENERAL_LAST_NAME"),
            "phone"            => "",
            "language"         => "",
            "avatar"           => "",
            "password"         => "",
            "salt"             => "",
            "reqPassChange"    => 0,
            "passExpiration"   => 0,
            "accessToken"      => "",
            "tokenExpiration"  => 0,
            "status"           => Status::Inactive(),
            "observations"     => "",
            "sendEmails"       => 0,
            "sendEmailNotis"   => 0,
            "timezone"         => 0,
            "currentLogin"     => 0,
            "lastLogin"        => 0,
            "askNotifications" => 0,
            "isDeleted"        => 1,
        ]);
    }

    /**
     * Parses the data and returns the fields
     * @param Request|array{} $request
     * @param integer         $level         Optional.
     * @param boolean|null    $reqPassChange Optional.
     * @return array{}[]
     */
    private static function getFields(Request|array $request, int $level = 0, ?bool $reqPassChange = null): array {
        $result = [];
        if (!empty($request["password"])) {
            $hash = self::createHash($request["password"]);
            $result["password"] = $hash["password"];
            $result["salt"]     = $hash["salt"];
        }
        if (!empty($level)) {
            $result["level"] = $level;
        }
        if ($reqPassChange !== null) {
            $result["reqPassChange"] = $reqPassChange ? 1 : 0;
        }
        return $result;
    }



    /**
     * Sets the current User for the given Credential
     * @param integer $credentialID
     * @param integer $userID
     * @return boolean
     */
    public static function setCurrentUser(int $credentialID, int $userID): bool {
        return self::schema()->edit($credentialID, [
            "currentUser" => $userID,
        ]);
    }

    /**
     * Updates the Language for the given Credential
     * @param integer $credentialID
     * @param string  $language
     * @return boolean
     */
    public static function setLanguage(int $credentialID, string $language): bool {
        $language = Strings::substringBefore($language, "-");
        return self::schema()->edit($credentialID, [
            "language" => $language,
        ]);
    }

    /**
     * Updates the Timezone for the given Credential
     * @param integer $credentialID
     * @param integer $timezone
     * @return boolean
     */
    public static function setTimezone(int $credentialID, int $timezone): bool {
        return self::schema()->edit($credentialID, [
            "timezone" => $timezone,
        ]);
    }

    /**
     * Updates the login time for the given Credential
     * @param integer $credentialID
     * @return boolean
     */
    public static function updateLoginTime(int $credentialID): bool {
        $query   = Query::create("CREDENTIAL_ID", "=", $credentialID);
        $current = self::schema()->getValue($query, "currentLogin");
        return self::schema()->edit($credentialID, [
            "lastLogin"    => $current,
            "currentLogin" => time(),
        ]);
    }

    /**
     * Sets the Credential Email
     * @param integer $credentialID
     * @param string  $email
     * @return boolean
     */
    public static function setEmail(int $credentialID, string $email): bool {
        return self::schema()->edit($credentialID, [
            "email" => $email,
        ]);
    }

    /**
     * Sets the Credential Avatar
     * @param integer $credentialID
     * @param string  $avatar
     * @return boolean
     */
    public static function setAvatar(int $credentialID, string $avatar): bool {
        return self::schema()->edit($credentialID, [
            "avatar" => $avatar,
        ]);
    }

    /**
     * Sets the Credential Level
     * @param integer $credentialID
     * @param integer $level
     * @return boolean
     */
    public static function setLevel(int $credentialID, int $level): bool {
        return self::schema()->edit($credentialID, [
            "level" => $level,
        ]);
    }

    /**
     * Sets the Credential Password
     * @param integer $credentialID
     * @param string  $password
     * @return array{}[]
     */
    public static function setPassword(int $credentialID, string $password): array {
        $hash = self::createHash($password);
        self::schema()->edit($credentialID, [
            "password"       => $hash["password"],
            "salt"           => $hash["salt"],
            "passExpiration" => 0,
            "reqPassChange"  => 0,
        ]);
        return $hash;
    }

    /**
     * Sets the require password change for the given Credential
     * @param integer $credentialID
     * @param boolean $require
     * @return boolean
     */
    public static function setReqPassChange(int $credentialID, bool $require): bool {
        return self::schema()->edit($credentialID, [
            "reqPassChange" => $require ? 1 : 0,
        ]);
    }

    /**
     * Sets a temporary Password to the given Credential
     * @param integer $credentialID
     * @param integer $hours        Optional.
     * @return string
     */
    public static function setTempPass(int $credentialID, int $hours = 48): string {
        $password = Strings::randomCode(10, "lud");
        $hash     = self::createHash($password);
        self::schema()->edit($credentialID, [
            "password"       => $hash["password"],
            "salt"           => $hash["salt"],
            "passExpiration" => time() + $hours * 3600,
            "reqPassChange"  => 1,
        ]);
        return $password;
    }

    /**
     * Sets an Access Token to the given Credential
     * @param integer $credentialID
     * @param integer $hours        Optional.
     * @return string
     */
    public static function setAccessToken(int $credentialID, int $hours = 4): string {
        $expiration = $hours > 0 ? time() + $hours * 3600 : 0;
        $credential = self::getOne($credentialID, true);
        if ($credential->tokenExpiration > 0 && $credential->tokenExpiration < time()) {
            $accessToken = Strings::randomCode(30, "lud");
            self::schema()->edit($credentialID, [
                "tokenExpiration" => $expiration,
            ]);
            return $credential->accessToken;
        }

        $accessToken = Strings::randomCode(30, "lud");
        self::schema()->edit($credentialID, [
            "accessToken"     => $accessToken,
            "tokenExpiration" => $expiration,
        ]);
        return $accessToken;
    }

    /**
     * Removes an Access Token of the given Credential
     * @param integer $credentialID
     * @return boolean
     */
    public static function removeAccessToken(int $credentialID): bool {
        return self::schema()->edit($credentialID, [
            "accessToken"     => "",
            "tokenExpiration" => 0,
        ]);
    }

    /**
     * Sets the Credential Status
     * @param integer $credentialID
     * @param integer $status
     * @return boolean
     */
    public static function setStatus(int $credentialID, int $status): bool {
        return self::schema()->edit($credentialID, [
            "status" => $status,
        ]);
    }

    /**
     * Stops asking the Credential for Notifications
     * @param integer $credentialID
     * @return boolean
     */
    public static function dontAskNotifications(int $credentialID): bool {
        return self::schema()->edit($credentialID, [
            "askNotifications" => 0,
        ]);
    }

    /**
     * Sets a Credential Value
     * @param integer $credentialID
     * @param string  $key
     * @param mixed   $value
     * @return boolean
     */
    public static function setValue(int $credentialID, string $key, mixed $value): bool {
        return self::schema()->edit($credentialID, [
            $key => $value,
        ]);
    }



    /**
     * Creates a Hash and Salt (if required) for the the given Password
     * @param string $pass
     * @param string $salt Optional.
     * @return array{}
     */
    public static function createHash(string $pass, string $salt = ""): array {
        $salt = !empty($salt) ? $salt : Strings::random(50);
        $hash = base64_encode(hash_hmac("sha256", $pass, $salt, true));
        return [ "password" => $hash, "salt" => $salt ];
    }

    /**
     * Creates a list of names and emails for the given array
     * @param array{}[] $data
     * @param string    $prefix     Optional.
     * @param boolean   $onlyActive Optional.
     * @return array{}[]
     */
    public static function createEmailList(array $data, string $prefix = "", bool $onlyActive = false): array {
        $result = [];
        $ids    = [];

        foreach ($data as $row) {
            if ($onlyActive) {
                $status = Arrays::getValue($row, "status", "", $prefix);
                if (!Status::isActive($status)) {
                    continue;
                }
            }
            $id = Arrays::getAnyValue($row, [ "credentialID", "id", "{$prefix}ID" ]);
            if (Arrays::contains($ids, $id)) {
                continue;
            }
            $result[] = [
                "credentialID"   => $id,
                "credentialName" => self::getName($row, $prefix),
                "email"          => Arrays::getValue($row, "email", "", $prefix),
            ];
            $ids[] = $id;
        }
        return $result;
    }

    /**
     * Returns a parsed Name for the given Credential
     * @param ArrayAccess|array{} $data
     * @param string              $prefix Optional.
     * @return string
     */
    public static function getName(ArrayAccess|array $data, string $prefix = ""): string {
        $id        = Arrays::getValue($data, "credentialID", "", $prefix);
        $firstName = Arrays::getValue($data, "firstName",    "", $prefix);
        $lastName  = Arrays::getValue($data, "lastName",     "", $prefix);
        $result    = "";

        if (!empty($firstName) && !empty($lastName)) {
            $result = "$firstName $lastName";
        }
        if (empty($result) && !empty($id)) {
            $result = "#$id";
        }
        return $result;
    }

    /**
     * Returns a parsed Phone for the given Credential
     * @param ArrayAccess|array{} $data
     * @param string              $prefix   Optional.
     * @param boolean             $withPlus Optional.
     * @return string
     */
    public static function getPhone(ArrayAccess|array $data, string $prefix = "", bool $withPlus = false): string {
        $phone     = Arrays::getValue($data, "phone",     "", $prefix);
        $cellphone = Arrays::getValue($data, "cellphone", "", $prefix);
        $iddRoot   = Arrays::getValue($data, "iddRoot",   "", $prefix);

        if (!empty($cellphone) && !empty($iddRoot)) {
            return ($withPlus ? "+" : "") . $iddRoot . $cellphone;
        }
        if (!empty($cellphone)) {
            return $cellphone;
        }
        return $phone;
    }

    /**
     * Returns a WhatsApp url
     * @param ArrayAccess|array{} $data
     * @param string              $prefix Optional.
     * @return string
     */
    public static function getWhatsAppUrl(ArrayAccess|array $data, string $prefix = ""): string {
        $whatsapp = self::getPhone($data, $prefix, false);
        return Utils::getWhatsAppUrl($whatsapp);
    }
}
