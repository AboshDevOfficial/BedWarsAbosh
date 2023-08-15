<?php


namespace BedWars\game;

use BedWars\BedWars;
use BedWars\game\shop\ItemShop;
use BedWars\game\shop\UpgradeShop;
use pocketmine\entity\Entity;
use BedWars\utils\Utils;
use pocketmine\utils\Config;
use pocketmine\block\Bed;
use pocketmine\block\Block;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\item\Item;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\Player;
use pocketmine\utils\TextFormat;
use pocketmine\math\Vector3;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\entity\object\PrimedTNT;

use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;

use onebone\economyapi\EconomyAPI;



class GameListener implements Listener
{

    /** @var BedWars $plugin */
    private $plugin;

    /**
     * GameListener constructor.
     * @param BedWars $plugin
     */
    public function __construct(BedWars $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * @param SignChangeEvent $event
     */
    public function onSignChange(SignChangeEvent $event) : void{
        $player = $event->getPlayer();
        $sign = $event->getBlock();

        if($event->getLine(0) == "bw" && $event->getLine(1) !== ""){
            if(!in_array($event->getLine(1), array_keys($this->plugin->games))){
                $player->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "§r§cArena doesn't exist!");
                return;
            }

            $dataFormat = $sign->getX() . ":" . $sign->getY() . ":" . $sign->getZ() . ":" . $player->level->getFolderName();
            $this->plugin->signs[$event->getLine(1)][] = $dataFormat;

            $location = $this->plugin->getDataFolder() . "arenas/" . $event->getLine(1) . ".json";
            if(!is_file($location)){
                //wtf ??
                return;
            }

            $fileContent = file_get_contents($location);
            $jsonData = json_decode($fileContent, true);
            $positionData = [
                "signs" => $this->plugin->signs[$event->getLine(1)]
            ];

            file_put_contents($location, json_encode(array_merge($jsonData, $positionData)));
            $player->sendMessage(BedWars::PREFIX . TextFormat::GREEN . "Join Sign created");

        }
    }

    /**
     * @param PlayerInteractEvent $event
     */
    public function onInteract(PlayerInteractEvent $event) : void{
        $player = $event->getPlayer();
        $block = $event->getBlock();

        foreach($this->plugin->signs as $arena => $positions){
            foreach($positions as $position) {
                $pos = explode(":", $position);
                if ($block->getX() == $pos[0] && $block->getY() == $pos[1] && $block->getZ() == $pos[2] && $player->level->getFolderName() == $pos[3]) {
                    $game = $this->plugin->games[$arena];
                    $game->join($player);
                    return;
                }
            }
        }

        $item = $event->getItem();

        if($item->getId() == Item::TNT){
	        $entity = Entity::createEntity("PrimedTNT", $player->getLevel(), Entity::createBaseNBT($player));
	        $entity->spawnToAll();
        }
        
        if($item->getId() == Item::WOOL){
            $teamColor = Utils::woolIntoColor($item->getDamage());

            $playerGame = $this->plugin->getPlayerGame($player);
            if($playerGame == null || $playerGame->getState() !== Game::STATE_LOBBY)return;

            if(!$player->hasPermission('lobby.team')){
                $player->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "§r§cYou don't have permission to use this");
                return;
            }

            $playerTeam = $this->plugin->getPlayerTeam($player);
            if($playerTeam !== null){
                $player->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "§r§cYou are already in a team!");
                return;
            }


            foreach($playerGame->teams as $team){
                if($team->getColor() == $teamColor){

                    if(count($team->getPlayers()) >= $playerGame->playersPerTeam){
                        $player->sendMessage(BedWars::PREFIX . TextFormat::RED . "§r§c" . $team->getName() . " team is full");
                        return;
                    }
                    $team->add($player);
                    $player->sendMessage(BedWars::PREFIX . TextFormat::GRAY . "§r§eYou've joined " . $team->getName() . " team");
                }
            }
        }elseif($item->getId() == Item::COMPASS){
            $playerGame = $this->plugin->getPlayerGame($player);
            if($playerGame == null)return;

            if($playerGame->getState() == Game::STATE_RUNNING){
                 $playerGame->trackCompass($player);
            }elseif($playerGame->getState() == Game::STATE_LOBBY){
                $playerGame->quit($player);
                $player->getInventory()->clearAll();
                $player->getArmorInventory()->clearAll();
                $player->getCursorInventory()->clearAll();
                $player->setFood(20);
                $player->setHealth(20);
                $player->teleport($this->plugin->getServer()->getDefaultLevel()->getSafeSpawn());
                $player->getInventory()->clearAll();
$level = $player->getLevel();
            $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_CONDUIT_ACTIVATE);
		$config = new Config("plugin_data/BedWars/kills.yml", Config::YAML);
        $config->getAll();
        $config->set($player->getName(), $config->remove($player->getName(), "               "));
        $config->set($player->getName(), $config->remove($player->getName(), "0"));
        $config->save();
        
        $xconfig = new Config("plugin_data/BedWars/Fkills.yml", Config::YAML);
        $xconfig->getAll();
        $xconfig->set($player->getName(), $xconfig->remove($player->getName(), "               "));
        $xconfig->set($player->getName(), $xconfig->remove($player->getName(), "0"));
        $xconfig->save();
        
        $bkconfig = new Config("plugin_data/BedWars/bed.yml", Config::YAML);
        $bkconfig->set($player->getName(), $bkconfig->remove($player->getName(), "               "));
        $bkconfig->set($player->getName(), $bkconfig->remove($player->getName(), "0"));
        $bkconfig->getAll();
                
            }
        }
    }
    
   
    /**
     * @param PlayerQuitEvent $event
     */
    public function onQuit(PlayerQuitEvent $event) : void{
        $player = $event->getPlayer();
        foreach($this->plugin->games as $game){
            if(in_array($player->getRawUniqueId(), array_keys(array_merge($game->players, $game->spectators)))){
                $game->quit($player);
            }
        }
    }

    /**
     * @param EntityLevelChangeEvent $event
     */
    public function onEntityLevelChange(EntityLevelChangeEvent $event) : void{
        $player = $event->getEntity();
        if(!$player instanceof Player){
            return;
        }


        $playerGame = $this->plugin->getPlayerGame($player);
        if($playerGame !== null && $event->getTarget()->getFolderName() !== $playerGame->worldName)$playerGame->quit($player);
    }

    /**
     * @param PlayerMoveEvent $event
     */
    public function onMove(PlayerMoveEvent $event) : void
    {
        $player = $event->getPlayer();
        foreach ($this->plugin->games as $game) {
            if (isset($game->players[$player->getRawUniqueId()])) {
                if ($game->getState() == Game::STATE_RUNNING) {
                    if($player->getY() < $game->getVoidLimit() && !$player->isSpectator()){
                        $playerTeam = $this->plugin->getPlayerTeam($player);

                    }
                }
            }
        }
    }

    /**
     * @param BlockBreakEvent $event
     */
    public function onBreak(BlockBreakEvent $event) : void
    {
        $player = $event->getPlayer();
        $block = $event->getBlock();

        if(isset($this->plugin->bedSetup[$player->getRawUniqueId()])){
            if(!$event->getBlock() instanceof Bed){
                $player->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "The block is not bed!");
                return;
            }
            $setup = $this->plugin->bedSetup[$player->getRawUniqueId()];

            $step =  (int)$setup['step'];

            $location = $this->plugin->getDataFolder() . "arenas/" . $setup['game'] . ".json";
            $fileContent = file_get_contents($location);
            $jsonData = json_decode($fileContent, true);

            $jsonData['teamInfo'][$setup['team']]['bedPos' . $step] = $block->getX() . ":" . $block->getY() . ":" . $block->getZ();
            file_put_contents($location, json_encode($jsonData));

            $player->sendMessage(BedWars::PREFIX . TextFormat::GREEN . "Bed $step has been set!");

            if($step == 2){
                unset($this->plugin->bedSetup[$player->getRawUniqueId()]);
                return;
            }

            $this->plugin->bedSetup[$player->getRawUniqueId()]['step']+=1;

            return;
        }

        $playerGame = $this->plugin->getPlayerGame($player);
        if($playerGame !== null){
            if($playerGame->getState() == Game::STATE_LOBBY){
                $event->setCancelled();
            }elseif($event->getBlock() instanceof Bed){
                $blockPos = $event->getBlock()->asPosition();

                $game = $this->plugin->getPlayerGame($player);
                $team = $this->plugin->getPlayerTeam($player);
                if($team == null || $game == null)return;

                foreach($game->teamInfo as $name => $info){
                    $bedPos = Utils::stringToVector(":", $info['bedPos1']);
                    $teamName = "";

                    if($bedPos->x == $blockPos->x && $bedPos->y == $blockPos->y && $bedPos->z == $blockPos->z){
                        $teamName = $name;
                    }else{
                        $bedPos = Utils::stringToVector(":", $info['bedPos2']);
                        if($bedPos->x == $blockPos->x && $bedPos->y == $blockPos->y && $bedPos->z == $blockPos->z){
                            $teamName = $name;
                        }
                    }

                    if($teamName !== ""){
                        $teamObject = $game->teams[$name];
                        if($name == $this->plugin->getPlayerTeam($player)->getName()){
                            $player->sendMessage(TextFormat::RED . "§r§cYou can't break your bed!");
                            $event->setCancelled();
                            return;
                        }
                        $event->setDrops([]);
                        $game->breakBed($teamObject, $player);

                    }
                }
            }else{
                if($playerGame->getState() == Game::STATE_RUNNING){
                    if(!in_array(Utils::vectorToString(":", $block->asVector3()), $playerGame->placedBlocks)){
                        $event->setCancelled();
                    }
                }
            }
        }
    }

    /**
     * @param BlockPlaceEvent $event
     */
    public function onPlace(BlockPlaceEvent $event) : void{
        $player = $event->getPlayer();
        
        $playerGame = $this->plugin->getPlayerGame($player);
        if($playerGame !== null){
            if($playerGame->getState() == Game::STATE_LOBBY){
                $event->setCancelled();
            }elseif($playerGame->getState() == Game::STATE_RUNNING){
                foreach($playerGame->teamInfo as $team => $data){
                    $spawn = Utils::stringToVector(":", $data['spawnPos']);
                    if($spawn->distance($event->getBlock()) < 6){
                        $event->setCancelled();
                    }else{
                        $playerGame->placedBlocks[] = Utils::vectorToString(":", $event->getBlock());
                    }
                }
            }
        }
    }
      
      
      public function onExplode(EntityExplodeEvent $event): void{
        $event->setCancelled();
        foreach($event->getBlockList() as $block){
            if($block instanceof Wool){
                
            }
            if($block instanceof Concrete){
                $event->setCancelled();
            }
            if($block instanceof Stone){
                $event->setCancelled();
            }
            if($block instanceof Grass){
                $event->setCancelled();
            }
        }
    }

    /**
     * @param EntityDamageEvent $event
     */
    public function onDamage(EntityDamageEvent $event) : void{
        $entity = $event->getEntity();
        foreach ($this->plugin->games as $game) {
            if ($entity instanceof Player && isset($game->players[$entity->getRawUniqueId()])) {

                if($game->getState() == Game::STATE_LOBBY){
                     $event->setCancelled();
                     return;
                }
                if($game->getState() == Game::STATE_REBOOT){
                     $event->setCancelled();
                     return;
                }


                if($event instanceof EntityDamageByEntityEvent){
                    $damager = $event->getDamager();

                    if(!$damager instanceof Player)return;

                    if(isset($game->players[$damager->getRawUniqueId()])){
                        $damagerTeam = $this->plugin->getPlayerTeam($damager);
                        $playerTeam = $this->plugin->getPlayerTeam($entity);

                        if($damagerTeam->getName() == $playerTeam->getName()){
                            $event->setCancelled();
                        }
                    }
                }

                if($event->getFinalDamage() >= $entity->getHealth()){
                    $game->killPlayer($entity);
                    $event->setCancelled();


                }

            }elseif(isset($game->npcs[$entity->getId()])){
                $event->setCancelled();

                if($event instanceof EntityDamageByEntityEvent){
                    $damager = $event->getDamager();

                    if($damager instanceof Player){
                        $npcTeam = $game->npcs[$entity->getId()][0];
                        $npcType = $game->npcs[$entity->getId()][1];

                        if(($game = $this->plugin->getPlayerGame($damager)) == null){
                            return;
                        }

                        if($game->getState() !== Game::STATE_RUNNING){
                            return;
                        }

                        $playerTeam = $this->plugin->getPlayerTeam($damager)->getName();
                        if($npcTeam !== $playerTeam && $npcType == "upgrade"){
                            $damager->sendMessage(TextFormat::RED . "§r§cYou can upgrade only your base!");
                            return;
                        }

                        if($npcType == "upgrade"){
                            UpgradeShop::sendDefaultShop($damager);
                        }else{
                            ItemShop::sendDefaultShop($damager);
                        }
                    }
                }
            }
        }
    }

    public function onCommandPreprocess(PlayerCommandPreprocessEvent $event) : void{
          $player = $event->getPlayer();

          $game = $this->plugin->getPlayerGame($player);

          if($game == null)return;

          $args = explode(" ", $event->getMessage());

          if($args[0] == '/fly' || isset($args[1]) && $args[1] == 'join'){
              $player->sendMessage(TextFormat::RED . "§r§cYou cannot run this in-game!");
              $event->setCancelled();
          }
    }



    /**
     * @param DataPacketReceiveEvent $event
     */
    public function handlePacket(DataPacketReceiveEvent $event) : void{
        $packet = $event->getPacket();
        $player = $event->getPlayer();


        if($packet instanceof ModalFormResponsePacket){
            $playerGame = $this->plugin->getPlayerGame($player);
            if($playerGame == null)return;
              $data = json_decode($packet->formData);
              if (is_null($data)) {
                return;
              }
                if($packet->formId == 50) {
                    ItemShop::sendPage($player, intval($data));

                }elseif($packet->formId < 100){
                    ItemShop::handleTransaction(($packet->formId), json_decode($packet->formData), $player, $this->plugin);
                }elseif($packet->formId == 100){
                    UpgradeShop::sendBuyPage(json_decode($packet->formData), $player, $this->plugin);
                }elseif($packet->formId > 100){
                    UpgradeShop::handleTransaction(($packet->formId), $player, $this->plugin);
                }
            }
    }
}