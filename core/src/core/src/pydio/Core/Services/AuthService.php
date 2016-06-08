<?php
/*
 * Copyright 2007-2013 Charles du Jeu - Abstrium SAS <team (at) pyd.io>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 */
namespace Pydio\Core\Services;
use Pydio\Auth\Core\AJXP_Safe;
use Pydio\Conf\Core\AbstractAjxpUser;
use Pydio\Core\Controller\Controller;
use Pydio\Core\Utils\BruteForceHelper;
use Pydio\Core\Utils\CookiesHelper;
use Pydio\Core\Utils\Utils;
use Pydio\Core\Controller\XMLWriter;
use Pydio\Log\Core\AJXP_Logger;

defined('AJXP_EXEC') or die( 'Access not allowed');

/**
 * Static access to the authentication mechanism. Encapsulates the authDriver implementation
 * @package Pydio
 * @subpackage Core
 */
class AuthService
{
    public static $useSession = true;
    private static $currentUser;
    public static $bufferedMessage = null;


    /**
     * Get a unique seed from the current auth driver
     * @static
     * @return int|string
     */
    public static function generateSeed()
    {
        $authDriver = ConfService::getAuthDriverImpl();
        return $authDriver->getSeed(true);
    }
    /**
     * Get the currently logged user object
     * @return AbstractAjxpUser
     */
    public static function getLoggedUser()
    {
        if (self::$useSession && isSet($_SESSION["AJXP_USER"])) {
            if (is_a($_SESSION["AJXP_USER"], "__PHP_Incomplete_Class")) {
                session_unset();
                return null;
            }
            return $_SESSION["AJXP_USER"];
        }
        if(!self::$useSession && isSet(self::$currentUser)) return self::$currentUser;
        return null;
    }

    /**
     * Log the user from its credentials
     * @static
     * @param string $user_id The user id
     * @param string $pwd The password
     * @param bool $bypass_pwd Ignore password or not
     * @param bool $cookieLogin Is it a logging from the remember me cookie?
     * @param string $returnSeed The unique seed
     * @return int
     */
    public static function logUser($user_id, $pwd, $bypass_pwd = false, $cookieLogin = false, $returnSeed="")
    {
        $user_id = UsersService::filterUserSensitivity($user_id);
        if ($cookieLogin && !isSet($_COOKIE["AjaXplorer-remember"])) {
            return -5; // SILENT IGNORE
        }
        if ($cookieLogin) {
            list($user_id, $pwd) = explode(":", $_COOKIE["AjaXplorer-remember"]);
        }
        $confDriver = ConfService::getConfStorageImpl();
        if ($user_id == null) {
            if (self::$useSession) {
                if(isSet($_SESSION["AJXP_USER"]) && is_object($_SESSION["AJXP_USER"])) {
                    /**
                     * @var AbstractAjxpUser $u
                     */
                    $u = $_SESSION["AJXP_USER"];
                    if($u->reloadRolesIfRequired()){
                        ConfService::getInstance()->invalidateLoadedRepositories();
                        self::$bufferedMessage = XMLWriter::reloadRepositoryList(false);
                        $_SESSION["AJXP_USER"] = $u;
                    }
                    return 1;
                }
            } else {
                if(isSet(self::$currentUser) && is_object(self::$currentUser)) return 1;
            }
            if (ConfService::getCoreConf("ALLOW_GUEST_BROWSING", "auth") && !isSet($_SESSION["CURRENT_MINISITE"])) {
                $authDriver = ConfService::getAuthDriverImpl();
                if (!$authDriver->userExists("guest")) {
                    UsersService::createUser("guest", "");
                    $guest = $confDriver->createUserObject("guest");
                    $guest->save("superuser");
                }
                self::logUser("guest", null);
                return 1;
            }
            return -1;
        }
        $authDriver = ConfService::getAuthDriverImpl();
        // CHECK USER PASSWORD HERE!
        $loginAttempt = BruteForceHelper::getBruteForceLoginArray();
        $bruteForceLogin = BruteForceHelper::checkBruteForceLogin($loginAttempt);
        BruteForceHelper::setBruteForceLoginArray($loginAttempt);

        if (!$authDriver->userExists($user_id)) {
            AJXP_Logger::warning(__CLASS__, "Login failed", array("user" => Utils::sanitize($user_id, AJXP_SANITIZE_EMAILCHARS), "error" => "Invalid user"));
            if ($bruteForceLogin === FALSE) {
                return -4;
            } else {
                return -1;
            }
        }
        if (!$bypass_pwd) {
            if (!UsersService::checkPassword($user_id, $pwd, $cookieLogin, $returnSeed)) {
                AJXP_Logger::warning(__CLASS__, "Login failed", array("user" => Utils::sanitize($user_id, AJXP_SANITIZE_EMAILCHARS), "error" => "Invalid password"));
                if ($bruteForceLogin === FALSE) {
                    return -4;
                } else {
                    if($cookieLogin) return -5;
                    return -1;
                }
            }
        }
        // Successful login attempt
        unset($loginAttempt[$_SERVER["REMOTE_ADDR"]]);
        BruteForceHelper::setBruteForceLoginArray($loginAttempt);

        // Setting session credentials if asked in config
        if (ConfService::getCoreConf("SESSION_SET_CREDENTIALS", "auth")) {
            list($authId, $authPwd) = $authDriver->filterCredentials($user_id, $pwd);
            AJXP_Safe::storeCredentials($authId, $authPwd);
        }

        $user = $confDriver->createUserObject($user_id);
        if ($user->getLock() == "logout") {
            AJXP_Logger::warning(__CLASS__, "Login failed", array("user" => Utils::sanitize($user_id, AJXP_SANITIZE_EMAILCHARS), "error" => "Locked user"));
            return -1;
        }

        if(AuthService::$useSession && ConfService::getCoreConf("ALLOW_GUEST_BROWSING", "auth")){
            ConfService::getInstance()->invalidateLoadedRepositories();
        }

        if ($authDriver->isAjxpAdmin($user_id)) {
            $user->setAdmin(true);
        }
        if(self::$useSession) $_SESSION["AJXP_USER"] = $user;
        else self::$currentUser = $user;

        if ($user->isAdmin()) {
            $user = RolesService::updateAdminRights($user);
            self::updateUser($user);
        }

        if ($authDriver->autoCreateUser() && !$user->storageExists()) {
            $user->save("superuser"); // make sure update rights now
        }
        AJXP_Logger::info(__CLASS__, "Log In", array("context"=>self::$useSession?"WebUI":"API"));
        return 1;
    }

    /**
     * Store the object in the session
     * @static
     * @param $userObject
     * @return void
     */
    public static function updateUser($userObject)
    {
        if(self::$useSession) $_SESSION["AJXP_USER"] = $userObject;
        else self::$currentUser = $userObject;
    }

    /**
     * Clear the session
     * @static
     * @return void
     */
    public static function disconnect()
    {
        if (isSet($_SESSION["AJXP_USER"]) || isSet(self::$currentUser)) {
            $user = isSet($_SESSION["AJXP_USER"]) ? $_SESSION["AJXP_USER"] : self::$currentUser;
            $userId = $user->id;
            Controller::applyHook("user.before_disconnect", array($user));
            CookiesHelper::clearRememberCookie($user);
            AJXP_Logger::info(__CLASS__, "Log Out", "");
            unset($_SESSION["AJXP_USER"]);
            //if(isSet(self::$currentUser)) unset(self::$currentUser);
            if (ConfService::getCoreConf("SESSION_SET_CREDENTIALS", "auth")) {
                AJXP_Safe::clearCredentials();
            }
            Controller::applyHook("user.after_disconnect", array($userId));
        }
    }


}