<?php
/*
 * Copyright 2007-2015 Abstrium <contact (at) pydio.com>
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
namespace Pydio\Core\Http\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Pydio\Authfront\Core\FrontendsLoader;
use Pydio\Core\Exception\ActionNotFoundException;
use Pydio\Core\Exception\AuthRequiredException;
use Pydio\Core\Exception\NoActiveWorkspaceException;
use Pydio\Core\Exception\PydioException;
use Pydio\Core\Exception\PydioUserAlertException;
use Pydio\Core\Exception\RepositoryLoadException;
use Pydio\Core\Http\Server;
use Pydio\Core\Model\Context;
use Pydio\Core\PluginFramework\PluginsService;
use Pydio\Core\Services\AuthService;
use Pydio\Core\Services\ConfService;
use Pydio\Core\Services\RolesService;
use Pydio\Core\Services\UsersService;
use Pydio\Core\Utils\Utils;
use Zend\Diactoros\Response\EmptyResponse;

defined('AJXP_EXEC') or die('Access not allowed');


class AuthMiddleware
{

    /**
     * @param ServerRequestInterface $requestInterface
     * @param \Psr\Http\Message\ResponseInterface $responseInterface
     * @return \Psr\Http\Message\ResponseInterface
     * @param callable|null $next
     * @throws PydioException
     */
    public static function handleRequest(\Psr\Http\Message\ServerRequestInterface &$requestInterface, \Psr\Http\Message\ResponseInterface &$responseInterface, callable $next = null){


        try{
            $driverImpl = ConfService::getAuthDriverImpl();
            PluginsService::getInstance()->setPluginUniqueActiveForType("auth", $driverImpl->getName(), $driverImpl);

            $response = FrontendsLoader::frontendsAsAuthMiddlewares($requestInterface, $responseInterface);
            if($response != null){
                return $response;
            }
            self::bootSessionServer($requestInterface);

        } catch (NoActiveWorkspaceException $ex){

            $logged = AuthService::getLoggedUser();
            if($logged !== null) $lock = $logged->getLock();
            if(empty($lock)){
                throw new AuthRequiredException();
            }

        } catch (RepositoryLoadException $r){
            
            ConfService::switchBackAfterRepositoryError($r->getRepository());
            throw $r;
            
        }

        $requestInterface = $requestInterface->withAttribute("ctx", Context::fromGlobalServices());
        try{

            return Server::callNextMiddleWare($requestInterface, $responseInterface, $next);

        }catch(ActionNotFoundException $a){

            if(AuthService::getLoggedUser() == null){
                throw new AuthRequiredException();
            }else{
                return new EmptyResponse();
            }
        } catch (RepositoryLoadException $r){

            ConfService::switchBackAfterRepositoryError($r->getRepository());
            throw $r;

        }

    }

    protected static function bootSessionServer(ServerRequestInterface &$request){

        $parameters = $request->getParsedBody();
        if (isSet($parameters["tmp_repository_id"])) {
            try{
                ConfService::switchRootDir($parameters["tmp_repository_id"], true);
            }catch(PydioException $e){}
        } else if (isSet($_SESSION["SWITCH_BACK_REPO_ID"])) {
            ConfService::switchRootDir($_SESSION["SWITCH_BACK_REPO_ID"]);
            unset($_SESSION["SWITCH_BACK_REPO_ID"]);
        }
        
        if (UsersService::usersEnabled()) {
            $loggedUser = AuthService::getLoggedUser();
            if ($loggedUser != null) {
                $res = ConfService::switchUserToActiveRepository($loggedUser, (isSet($parameters["tmp_repository_id"])?$parameters["tmp_repository_id"]:"-1"));
                if (!$res) {
                    throw new NoActiveWorkspaceException();
                }
            }
        }

        //Set language
        $loggedUser = AuthService::getLoggedUser();
        if($loggedUser != null && $loggedUser->getPref("lang") != "") ConfService::setLanguage($loggedUser->getPref("lang"));
        else if(isSet($request->getCookieParams()["AJXP_lang"])) ConfService::setLanguage($request->getCookieParams()["AJXP_lang"]);

        if(UsersService::usersEnabled() && Utils::detectApplicationFirstRun()){
            try{
                RolesService::bootSequence();
            }catch (PydioException $e){
                if($request->getAttribute("action") == "get_boot_gui"){
                    $request = $request->withAttribute("flash", $e->getMessage());
                }else{
                    throw $e;
                }
            }
        }

    }

}