<?php
namespace Framework\Auth;

use Framework\System\AccessCode;
use Framework\System\ConfigCode;
use Framework\System\StatusCode;
use Framework\Auth\AuthToken;
use Framework\Auth\Credential;
use Framework\Auth\Reset;
use Framework\Auth\Spam;
use Framework\NLS\NLS;
use Framework\File\File;
use Framework\File\FilePath;
use Framework\Log\ActionLog;
use Framework\Utils\Arrays;
use Framework\Utils\DateTime;
use Framework\Utils\Strings;
use Framework\Schema\CredentialEntity;

/**
 * The Auth
 */
class Auth {

    private static string $refreshToken = "";
    private static bool   $sendRefresh  = false;

    private static string $accessName   = AccessCode::General;
    private static int    $credentialID = 0;
    private static int    $adminID      = 0;
    private static int    $userID       = 0;
    private static string $apiToken     = "";

    private static ?CredentialEntity $credential = null;
    private static ?CredentialEntity $admin      = null;



    /**
     * Returns true if the Auth Login is disabled
     * @return boolean
     */
    public static function isLoginDisabled(): bool {
        return ConfigCode::getBoolean("authIsDisabled");
    }

    /**
     * Validates the Credential
     * @param string       $accessToken
     * @param string       $refreshToken Optional.
     * @param string|null  $langcode     Optional.
     * @param integer|null $timezone     Optional.
     * @return boolean
     */
    public static function validateCredential(string $accessToken, string $refreshToken = "", ?string $langcode = null, ?int $timezone = null): bool {
        Reset::deleteOld();
        AuthToken::deleteOld();

        // Validate the API Token
        $apiToken = AuthToken::getAPIToken($accessToken);
        if (!empty($apiToken)) {
            return self::validateAPI($apiToken);
        }

        // Validate the Credential Tokens
        if (!AuthToken::isValid($accessToken, $refreshToken)) {
            return false;
        }

        // Retrieve the Token data
        [ $credentialID, $adminID ] = AuthToken::getCredentials($accessToken, $refreshToken);
        $credential = Credential::getByID($credentialID, true);
        if ($credential->isEmpty() || $credential->isDeleted) {
            return false;
        }

        // Retrieve the Admin
        $admin = new CredentialEntity();
        if (!empty($adminID)) {
            $admin = Credential::getByID($adminID, true);
        }

        // Update the Refresh Token
        self::$refreshToken = $refreshToken;
        $newRefreshToken = AuthToken::updateRefreshToken($accessToken, $refreshToken);
        if (!empty($newRefreshToken) && $newRefreshToken !== $refreshToken) {
            self::$refreshToken = $newRefreshToken;
            self::$sendRefresh  = true;
        }

        // Set the new Language and Timezone if required
        self::setLanguageTimezone($credential, $admin, $langcode, $timezone);

        // Set the Credential
        self::setCredential($credential, $admin, $credential->currentUser);

        // Start or reuse a log session
        ActionLog::startSession();
        return true;
    }

    /**
     * Sets the Language and Timezone if required
     * @param CredentialEntity $credential
     * @param CredentialEntity $admin
     * @param string|null      $langcode
     * @param integer|null     $timezone
     * @return boolean
     */
    private static function setLanguageTimezone(CredentialEntity $credential, CredentialEntity $admin, ?string $langcode = null, ?int $timezone = null): bool {
        $model = $credential;
        if (!$admin->isEmpty()) {
            $model = $admin;
        }

        if (!empty($langcode) && !$model->has("language")) {
            Credential::setLanguage($model->id, $langcode);
            $model->language = $langcode;
        }
        if (!empty($timezone)) {
            Credential::setTimezone($model->id, $timezone);
            $model->timezone = $timezone;
        }
        return true;
    }

    /**
     * Returns the API Token
     * @return string
     */
    public static function getApiToken(): string {
        return ConfigCode::getString("authApiToken");
    }

    /**
     * Validates and Sets the auth as API
     * @param string $token
     * @return boolean
     */
    public static function validateAPI(string $token): bool {
        if ($token === self::getApiToken()) {
            self::$apiToken   = $token;
            self::$accessName = AccessCode::API;
            return true;
        }
        return false;
    }

    /**
     * Validates and Sets the auth as API Internal
     * @return boolean
     */
    public static function validateInternal(): bool {
        self::$accessName = AccessCode::API;
        return true;
    }



    /**
     * Checks the Spam Protection for the Login
     * @return boolean
     */
    public static function spamProtection(): bool {
        return Spam::protect();
    }

    /**
     * Logins the given Credential
     * @param CredentialEntity $credential
     * @return boolean
     */
    public static function login(CredentialEntity $credential): bool {
        $isNew = self::$credentialID !== $credential->id;
        self::setCredential($credential, null, $credential->currentUser);

        Credential::updateLoginTime($credential->id);
        ActionLog::startSession(true);

        $path = FilePath::getTempPath($credential->id, false);
        File::emptyDir($path);
        Reset::delete($credential->id);

        if ($isNew) {
            self::$refreshToken = AuthToken::createRefreshToken($credential->id);
            self::$sendRefresh  = true;
        }
        return true;
    }

    /**
     * Logouts the Current Credential
     * @return boolean
     */
    public static function logout(): bool {
        AuthToken::deleteRefreshToken(self::$refreshToken);
        ActionLog::endSession();

        self::$refreshToken = "";
        self::$sendRefresh  = false;
        self::$accessName   = AccessCode::General;
        self::$credential   = null;
        self::$credentialID = 0;
        self::$adminID      = 0;
        self::$userID       = 0;
        return true;
    }

    /**
     * Returns true if the Credential can login
     * @param CredentialEntity $credential
     * @return boolean
     */
    public static function canLogin(CredentialEntity $credential): bool {
        return (
            !$credential->isEmpty() &&
            !$credential->isDeleted &&
            $credential->status === StatusCode::Active
        );
    }



    /**
     * Logins as the given Credential from an Admin account
     * @param integer $credentialID
     * @return boolean
     */
    public static function loginAs(int $credentialID): bool {
        $admin = self::$credential;
        $user  = Credential::getByID($credentialID, true);

        if (self::canLoginAs($admin, $user)) {
            self::setCredential($user, $admin, $user->currentUser);
            return true;
        }
        return false;
    }

    /**
     * Logouts as the current Credential and logins back as the Admin
     * @return integer
     */
    public static function logoutAs(): int {
        if (!self::isLoggedAsUser()) {
            return 0;
        }

        if (self::canLoginAs(self::$admin, self::$credential)) {
            self::setCredential(self::$admin);
            return self::$credential->id;
        }
        return 0;
    }

    /**
     * Returns the Credential to Login from the given Email
     * @param string $email
     * @return CredentialEntity
     */
    public static function getLoginCredential(string $email): CredentialEntity {
        $parts = Strings::split($email, "|");
        $user  = null;

        if (!empty($parts[0]) && !empty($parts[1])) {
            $admin = Credential::getByEmail($parts[0], true);
            $user  = Credential::getByEmail($parts[1], true);

            if (self::canLoginAs($admin, $user)) {
                $user->password = $admin->password;
                $user->salt     = $admin->salt;
                $user->adminID  = $admin->id;
            }
        } else {
            $user = Credential::getByEmail($email, true);
        }
        return $user;
    }

    /**
     * Returns true if the Admin can login as the User
     * @param CredentialEntity $admin
     * @param CredentialEntity $user
     * @return boolean
     */
    public static function canLoginAs(CredentialEntity $admin, CredentialEntity $user): bool {
        return (
            self::canLogin($admin) &&
            !$user->isEmpty() &&
            AccessCode::getLevel($admin->access) >= AccessCode::getLevel($user->access) &&
            AccessCode::inGroup(AccessCode::Admin, $admin->access)
        );
    }



    /**
     * Sets the Credential
     * @param CredentialEntity      $credential
     * @param CredentialEntity|null $admin      Optional.
     * @param integer               $userID     Optional.
     * @return boolean
     */
    public static function setCredential(CredentialEntity $credential, ?CredentialEntity $admin = null, int $userID = 0): bool {
        self::$credential   = $credential;
        self::$credentialID = $credential->id;
        self::$accessName   = !empty($credential->userAccess) ? $credential->userAccess : $credential->access;
        self::$userID       = $userID;

        $language = $credential->language;
        $timezone = $credential->timezone;
        if (!empty($admin) && !$admin->isEmpty()) {
            self::$admin   = $admin;
            self::$adminID = $admin->id;
            $language = $admin->language;
            $timezone = $admin->timezone;
        } else {
            self::$admin   = null;
            self::$adminID = 0;
        }

        if (!empty($language)) {
            NLS::setLanguage($language);
        }

        $accesses = ConfigCode::getArray("authTimezone");
        if (!empty($timezone) && (empty($accesses) || Arrays::contains($accesses, $credential->access))) {
            DateTime::setTimezone($timezone);
        }
        return true;
    }

    /**
     * Updates the Credential
     * @return boolean
     */
    public static function updateCredential(): bool {
        if (!empty(self::$credentialID)) {
            $credential = Credential::getByID(self::$credentialID, true);
            if (!$credential->isEmpty()) {
                self::$credential = $credential;
                return true;
            }
        }
        return false;
    }

    /**
     * Sets the Current User
     * @param integer $userID
     * @param string  $accessName
     * @return boolean
     */
    public static function setCurrentUser(int $userID, string $accessName): bool {
        self::$userID     = $userID;
        self::$accessName = $accessName;
        ActionLog::endSession();
        ActionLog::startSession(true);
        return true;
    }

    /**
     * Creates and returns the Access token
     * @return string
     */
    public static function getAccessToken(): string {
        // Create an API Token
        if (self::hasAPI()) {
            return AuthToken::createAccessToken([
                "isAPI"    => true,
                "apiToken" => self::$apiToken,
            ]);
        }

        // Check if there is a Credential
        if (!self::hasCredential()) {
            return "";
        }

        // The general data
        $data = [
            "accessName"       => self::$accessName,
            "credentialID"     => self::$credentialID,
            "adminID"          => self::$adminID,
            "userID"           => self::$userID,
            "email"            => self::$credential->email,
            "name"             => self::$credential->credentialName,
            "language"         => self::$credential->language,
            "avatar"           => self::$credential->avatar,
            "appearance"       => self::$credential->appearance,
            "reqPassChange"    => self::$credential->reqPassChange,
            "askNotifications" => self::$credential->askNotifications,
            "loggedAsUser"     => self::isLoggedAsUser(),
            "isAdmin"          => self::isAdmin(),
        ];

        // Add fields from the Config
        $fields = ConfigCode::getArray("authFields");
        foreach ($fields as $field) {
            $data[$field] = self::$credential->get($field);
        }

        return AuthToken::createAccessToken($data);
    }

    /**
     * Returns the Refresh Token
     * @return string
     */
    public static function getRefreshToken(): string {
        if (!self::hasCredential() || !self::$sendRefresh) {
            return "";
        }
        return self::$refreshToken;
    }



    /**
     * Returns the Credential Entity
     * @return CredentialEntity
     */
    public static function getCredential(): CredentialEntity {
        if (empty(self::$credential)) {
            return new CredentialEntity();
        }
        return self::$credential;
    }

    /**
     * Returns the Credential ID
     * @return integer
     */
    public static function getID(): int {
        return self::$credentialID;
    }

    /**
     * Returns the Admin ID
     * @return integer
     */
    public static function getAdminID(): int {
        return self::$adminID;
    }

    /**
     * Returns the Credential Current User
     * @return integer
     */
    public static function getUserID(): int {
        return self::$userID;
    }

    /**
     * Returns the Access Name
     * @return string
     */
    public static function getAccessName(): string {
        return self::$accessName;
    }

    /**
     * Returns the path used to store the temp files
     * @return string
     */
    public static function getTempPath(): string {
        if (empty(self::$credentialID)) {
            return "";
        }
        return FilePath::getTempPath(self::$credentialID);
    }



    /**
     * Returns true if the User is Logged in
     * @return boolean
     */
    public static function isLoggedIn(): bool {
        return self::hasCredential() || self::hasAPI();
    }

    /**
     * Returns true or false if the admin is logged as an user
     * @return boolean
     */
    public static function isLoggedAsUser(): bool {
        return !empty(self::$adminID);
    }

    /**
     * Returns true if there is a Credential
     * @return boolean
     */
    public static function hasCredential(): bool {
        return !empty(self::$credentialID);
    }

    /**
     * Returns true if there is an API
     * @return boolean
     */
    public static function hasAPI(): bool {
        return self::$accessName === AccessCode::API;
    }

    /**
     * Returns true or false if the current used is actually an admin
     * @return boolean
     */
    public static function isAdmin(): bool {
        return AccessCode::inGroup(AccessCode::Admin, self::$credential->access);
    }



    /**
     * Returns true if the password is correct for the current auth
     * @param string $password
     * @return boolean
     */
    public static function isPasswordCorrect(string $password): bool {
        return Credential::isPasswordCorrect(self::$credentialID, $password);
    }

    /**
     * Returns true if the current Auth requires login
     * @param string $accessName
     * @return boolean
     */
    public static function requiresLogin(string $accessName): bool {
        return $accessName !== AccessCode::General && !self::isLoggedIn();
    }

    /**
     * Returns true if the user has that Access
     * @param string $accessName
     * @return boolean
     */
    public static function grant(string $accessName): bool {
        if (AccessCode::inGroup(AccessCode::API, self::$accessName)) {
            return (
                AccessCode::inGroup(AccessCode::API, $accessName) ||
                AccessCode::inGroup(AccessCode::General, $accessName)
            );
        }

        $currentLevel   = AccessCode::getLevel(self::$accessName);
        $requestedLevel = AccessCode::getLevel($accessName);
        return $currentLevel >= $requestedLevel;
    }
}
