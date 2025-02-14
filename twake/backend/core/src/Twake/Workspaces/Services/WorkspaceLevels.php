<?php

namespace Twake\Workspaces\Services;


use Twake\Workspaces\Entity\WorkspaceLevel;
use Twake\Workspaces\Model\WorkspaceLevelsInterface;
use App\App;

class WorkspaceLevels
{

    private $doctrine;
    private $pusher;

    public function __construct(App $app)
    {
        $this->doctrine = $app->getServices()->get("app.twake_doctrine");
        $this->pusher = $app->getServices()->get("app.pusher");
    }

    public function getLevel($workspaceId, $userId, $currentUserId = null)
    {
        if ($currentUserId == null
            || $currentUserId == $userId
            || $this->can($workspaceId, $currentUserId, "")
        ) {

            $userRepository = $this->doctrine->getRepository("Twake\Users:User");
            $workspaceRepository = $this->doctrine->getRepository("Twake\Workspaces:Workspace");
            $workspaceUserRepository = $this->doctrine->getRepository("Twake\Workspaces:WorkspaceUser");

            $user = $userRepository->find($userId);
            $workspace = $workspaceRepository->findOneBy(["id"=>$workspaceId]);
            $link = $workspaceUserRepository->findOneBy(Array("workspace_id" => $workspace->getId(), "user_id" => $user->getId()));

            if (!$link) {
                return null; //No level because no member
            }
            $level = $this->doctrine->getRepository("Twake\Workspaces:WorkspaceLevel")->findOneBy(Array("workspace_id" => $workspace->getId(), "id" => $link->getLevelId()));

            return $level;

        }

        return null; //Cant look this info
    }

    public function can($workspaceId, $userId, $action)
    {

        if (!$userId) {
            return false;
        }

        //Load rights for this users

        $workspaceRepository = $this->doctrine->getRepository("Twake\Workspaces:Workspace");
        $workspaceUserRepository = $this->doctrine->getRepository("Twake\Workspaces:WorkspaceUser");
        $companyUserRepository = $this->doctrine->getRepository("Twake\Workspaces:GroupUser");

        $user = $this->convertToEntity($userId, "Twake\Users:User");
        $workspace = $workspaceRepository->findOneBy(["id"=>$workspaceId]);

        if (!$user || !$workspace) {
            error_log("no user / ws ");
            return false;
        }

        $companyLink = $companyUserRepository->findOneBy(Array("group" => $workspace->getGroup(), "user" => $user->getId()));
        $link = $workspaceUserRepository->findOneBy(Array("workspace_id" => $workspace->getId(), "user_id" => $user->getId()));
        if ($link) {

            if($link->getRole() === "moderator" || $companyLink->getRole() === "admin" || $companyLink->getRole() === "owner"){
                return true; //Admin can do everything
            }

            $level = $this->doctrine->getRepository("Twake\Workspaces:WorkspaceLevel")->findOneBy(Array("workspace_id" => $workspace->getId(), "id" => $link->getLevelId()));
            if ($level && $level->getIsAdmin()) {
                return true; //Admin can do everything
            }

            if($action === "admin"){
                return false;
            }

            if ($action == "" || $action == null) {
                return true;
            }

            return false;
        }

        return false;

    }

    private function convertToEntity($var, $repository)
    {
        if (is_string($var)) {
            $var = $var; // Cassandra id do nothing
        }

        if (is_int($var) || is_string($var) || get_class($var) == "Ramsey\Uuid\Uuid") {
            return $this->doctrine->getRepository($repository)->find($var);
        } else if (is_object($var)) {
            return $var;
        } else {
            return null;
        }

    }

    public function updateLevel($workspaceId, $levelId, $label, $rights, $currentUserId = null)
    {
        if ($currentUserId == null
            || $this->can($workspaceId, $currentUserId, "workspace:write")
        ) {

            $levelRepository = $this->doctrine->getRepository("Twake\Workspaces:WorkspaceLevel");

            $level = $levelRepository->findBy(Array("workspace_id" => $workspaceId, "level" => $levelId));
            if (!$level) {
                return false;
            }

            if ($level->getWorkspace()->getId() != $workspaceId) {
                return false;
            }

            $level->setRights($rights);
            $level->setLabel($label);

            $this->doctrine->persist($level);
            $this->doctrine->flush();

            $datatopush = Array(
                "type" => "CHANGE_LEVEL",
                "data" => Array(
                    "workspaceId" => $workspaceId,
                )
            );
            $this->pusher->push($datatopush, "group/" . $workspaceId);

            return true;

        }

        return false;
    }

    public function getDefaultLevel($workspaceId)
    {

        $choosen = null;

        $levelRepository = $this->doctrine->getRepository("Twake\Workspaces:WorkspaceLevel");

        $levels = $levelRepository->findBy(Array("workspace_id" => $workspaceId));


        foreach ($levels as $level) {
            if ($level->getIsDefault()) {
                $choosen = $level;
            }
        }

        //No default level !
        if (!$choosen) {

            $levelD = new WorkspaceLevel();
            $levelD->setWorkspace($workspaceId);
            $levelD->setLabel("Default");
            $levelD->setIsAdmin(false);
            $levelD->setIsDefault(true);
            $this->doctrine->persist($levelD);
            $this->doctrine->flush();

            $choosen = $levelD;

        }

        return $choosen;

    }

    public function setDefaultLevel($workspaceId, $levelId, $currentUserId = null)
    {
        if ($currentUserId == null
            || $this->can($workspaceId, $currentUserId, "workspace:write")
        ) {

            $levelRepository = $this->doctrine->getRepository("Twake\Workspaces:WorkspaceLevel");

            $oldLevelDefault = $levelRepository->findOneBy(Array("workspace_id" => $workspaceId, "isdefault" => true));

            if ($oldLevelDefault) {
                $oldLevelDefault->setisDefault(false);
                $this->doctrine->persist($oldLevelDefault);
            }

            $levelDefault = $levelRepository->find($levelId);
            if (!$levelDefault) {
                return false;
            }
            if ($levelDefault->getWorkspace()->getId() != $workspaceId) {
                return false;
            }
            $levelDefault->setisDefault(true);


            $this->doctrine->persist($levelDefault);
            $this->doctrine->flush();

            return true;

        }

        return false;
    }

    public function addLevel($workspaceId, $label, $rights, $currentUserId = null)
    {
        if ($currentUserId == null
            || $this->can($workspaceId, $currentUserId, "workspace:write")
        ) {
            $workspaceRepository = $this->doctrine->getRepository("Twake\Workspaces:Workspace");
            $workspace = $workspaceRepository->findOneBy(["id"=>$workspaceId]);

            $level = new WorkspaceLevel();

            $level->setWorkspace($workspaceId);
            $level->setRights($rights);
            $level->setLabel($label);

            $this->doctrine->persist($level);
            $this->doctrine->flush();

            return true;

        }

        return false;
    }

    public function removeLevel($workspaceId, $levelId, $currentUserId = null)
    {
        if ($currentUserId == null
            || $this->can($workspaceId, $currentUserId, "workspace:write")
        ) {

            $levelRepository = $this->doctrine->getRepository("Twake\Workspaces:WorkspaceLevel");
            $workspaceRepository = $this->doctrine->getRepository("Twake\Workspaces:Workspace");
            $workspaceUserRepository = $this->doctrine->getRepository("Twake\Workspaces:WorkspaceUser");

            $level = $levelRepository->findBy(Array("workspace_id" => $workspaceId, "level" => $levelId));
            if ($level->getWorkspace()->getId() != $workspaceId) {
                return false;
            }

            if ($level->getIsDefault()) {
                return false; //Can't remove default level
            }

            if ($level->getIsAdmin()) {
                return false; //Can't remove admin level
            }

            $levelDefault = $levelRepository->findOneBy(Array("workspace_id" => $workspaceId, "isdefault" => true));

            if (!$levelDefault) {
                return false;
            }

            $affectedUsers = $workspaceUserRepository->findBy(Array("workspace_id" => $workspaceId, "level" => $level));
            foreach ($affectedUsers as $affectedUser) {
                $affectedUser->setLevel($levelDefault);
                $this->doctrine->persist($affectedUser);
            }

            $this->doctrine->remove($level);
            $this->doctrine->flush();

            $datatopush = Array(
                "type" => "CHANGE_LEVEL",
                "data" => Array(
                    "workspaceId" => $workspaceId(),
                )
            );
            $this->pusher->push($datatopush, "group/" . $workspaceId());

            return true;

        }

        return false;
    }

    public function getUsers($workspaceId, $levelId, $currentUserId = null)
    {
        if ($currentUserId == null
            || $this->can($workspaceId, $currentUserId, "workspace:read")
        ) {

            $levelRepository = $this->doctrine->getRepository("Twake\Workspaces:WorkspaceLevel");
            $workspaceRepository = $this->doctrine->getRepository("Twake\Workspaces:Workspace");
            $workspaceUserRepository = $this->doctrine->getRepository("Twake\Workspaces:WorkspaceUser");

            $level = $levelRepository->findBy(Array("workspace_id" => $workspaceId, "level" => $levelId));
            $workspace = $workspaceRepository->findOneBy(["id"=>$workspaceId]);

            if (!$level || !$workspace) {
                return false;
            }

            $link = $workspaceUserRepository->findBy(Array("level" => $level, "workspace_id" => $workspaceId));

            $users = Array();
            foreach ($link as $user) {
                $users[] = $user->getUser($this->doctrine);
            }

            return $users;
        }

        return false;
    }

    public function getLevels($workspaceId, $currentUserId = null)
    {
        $levelRepository = $this->doctrine->getRepository("Twake\Workspaces:WorkspaceLevel");
        $workspaceRepository = $this->doctrine->getRepository("Twake\Workspaces:Workspace");

        $workspace = $workspaceRepository->findOneBy(["id"=>$workspaceId]);

        if (!$workspace) {
            return false;
        }

        $levels = $levelRepository->findBy(Array("workspace_id" => $workspaceId));

        return $levels;
    }

    public function getByLabel($workspaceId, $label, $currentUserId = null)
    {
        if ($currentUserId == null
            || $this->can($workspaceId, $currentUserId, "workspace:read")
        ) {

            $levelRepository = $this->doctrine->getRepository("Twake\Workspaces:WorkspaceLevel");
            $workspaceRepository = $this->doctrine->getRepository("Twake\Workspaces:Workspace");

            $workspace = $workspaceRepository->findOneBy(["id"=>$workspaceId]);

            if (!$workspace) {
                return false;
            }

            $levels = $levelRepository->findBy(["label" => $label, "workspace_id" => $workspaceId]);

            return $levels;
        }

        return false;
    }


    // @Depreciated
    public function hasRight($userId, $workspaceId, $rightAsked)
    {
        $userId = $this->convertToEntity($userId, "Twake\Users:User");
        $userId = $userId->getId();

        $workspaceId = $this->convertToEntity($workspaceId, "Twake\Workspaces:Workspace");
        $workspaceId = $workspaceId->getId();

        return $this->can($workspaceId, $userId, $rightAsked);
    }

    // @Depreciated
    public function errorsAccess($userId, $workspaceId, $right)
    {
        $userId = $this->convertToEntity($userId, "Twake\Users:User");
        $userId = $userId->getId();

        $workspaceId = $this->convertToEntity($workspaceId, "Twake\Workspaces:Workspace");
        $workspaceId = $workspaceId->getId();

        if ($this->can($workspaceId, $userId, $right)) {
            return [];
        }
        return ["notallowed"];
    }

}

?>
