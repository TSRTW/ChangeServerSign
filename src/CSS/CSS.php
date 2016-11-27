<?php

namespace CSS;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Config;
use pocketmine\math\Vector3;
use pocketmine\level\Level;
use pocketmine\network\Network;
use pocketmine\scheduler\CallbackTask;

class CSS extends PluginBase implements Listener{

	private $signs = [];
	/**
	* x-y-z-level:
	* 	IP:
	* 	Port:
	* 	level:
	* 	x:
	* 	y:
	* 	z:
	**/
	public $config;
	public function onLoad(){
		$this->getLogger()->info(TextFormat::WHITE . "loaded!");
	}

	public function onEnable(){
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		@mkdir($this->getDataFolder(), 0777, true);
		$this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, array());
		$this->signs = $this->config->getAll();
		foreach ($this->signs as $key => $sign) {
			$getsign = $this->getServer()->getLevelByName($sign->level)->getTile(new Vector3($sign['x'],$sign['y'],$sign['z']));
			if ($getsign == null) {
				unset($this->signs[$key]);
				$this->getLogger()->info(TextFormat::RED . "发现不存在配置文件所记录的牌子:".$key. ", 已经自动删除该位置的记录!");
				$this->config->setAll($this->signs);
				$this->config->save();
			}
		}
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask([$this,"updataSign"]), 100);
		$this->getLogger()->info(TextFormat::DARK_GREEN . "enabled!");
    }

	public function onDisable(){
		$this->getLogger()->info(TextFormat::DARK_RED . "disabled!");
	}

	public function updataSign(){
		foreach ($this->signs as $sign) {
			//$this->getLogger()->info(TextFormat::GREEN . "开始Task ".$sign["IP"].":".$sign["Port"]);
			$task = new SignAsyncTask();
			$task->setDate($sign["IP"], $sign["Port"], $sign["x"], $sign["y"], $sign["z"], $sign["level"]);
			$this->getServer()->getScheduler()->scheduleAsyncTask($task);
		}
	}

	/**
	 * @param SignChangeEvent $event
	 */
	public function onChange(SignChangeEvent $event){
		$text = $event->getLines();
		if ($text[0] == "[跨服傳送]" OR $text[0] == TextFormat::DARK_GREEN . "[跨服傳送]") {
			if($event->getPlayer()->hasPermission("interserversign.sign.build")){
				$x = $event->getBlock()->getX();
				$y = $event->getBlock()->getY();
				$z = $event->getBlock()->getZ();
				$level = $event->getBlock()->getLevel()->getFolderName();
				$this->signs[$x."-".$y."-".$z."-".$level] = array(
						"IP" => $text[1].$text[2],
						"Port" => $text[3],
						"x" => $x,
						"y" => $y,
						"z" => $z,
						"level" => $level
					);
				$this->config->setAll($this->signs);
				$this->config->save();
				$event->setLine(0, TextFormat::DARK_GREEN . "[跨服傳送]");
				$event->setLine(1, TextFormat::RED . "[Written by]");
				$event->setLine(2, TextFormat::GREEN . "[TSR.TW]");
				$event->setLine(3, TextFormat::WHITE . "[加載中...]");
				$event->getPlayer()->sendMessage(TextFormat::RED . "设置跨服傳送牌子");
			}else{
				$event->getPlayer()->sendMessage(TextFormat::RED . "你没有权限设置跨服傳送牌子");
				$event->setCancelled();
			}
		}
	}

	/**
	 * @param BlockBreakEvent $event
	 */
	public function onBreak(BlockBreakEvent $event){
		$id = $event->getBlock()->getId();
		if ($id == 63 OR $id == 68) {
			$block = $event->getBlock();
			$sign = $event->getBlock()->getLevel()->getTile(new Vector3($block->getX(),$block->getY(),$block->getZ()));
			if ($sign->getText()[0] == TextFormat::DARK_GREEN . "[跨服傳送]" OR $sign->getText()[0] == "[跨服傳送]") {
				if (!$event->getPlayer()->hasPermission("interserversign.sign.build")) {
					$event->getPlayer()->sendMessage(TextFormat::RED . "你没有权限破坏跨服傳送牌子");
					$event->setCancelled();
				}else{
					$x = $block->getX();
					$y = $block->getY();
					$z = $block->getZ();
					$level = $block->getLevel()->getFolderName();
					unset($this->signs[$x."-".$y."-".$z."-".$level]);
					$this->config->setAll($this->signs);
					$this->config->save();
					$event->getPlayer()->sendMessage(TextFormat::RED . "跨服傳送牌子删除");
				}
			}
		}
	}

	/**
	 * @param PlayerInteractEvent $event
	 */
	public function onInteract(PlayerInteractEvent $event){
		$id = $event->getBlock()->getId();
		if ($id == 63 OR $id == 68) {
			$block = $event->getBlock();
			$x = $block->getX();
			$y = $block->getY();
			$z = $block->getZ();
			$level = $block->getLevel()->getFolderName();
			$sign = $event->getBlock()->getLevel()->getTile(new Vector3($block->getX(),$block->getY(),$block->getZ()));
			if (($sign->getText()[0] == TextFormat::DARK_GREEN . "[跨服傳送]" OR $sign->getText()[0] == "[跨服傳送]") AND $event->getPlayer()->hasPermission("interserversign.sign.tp")) {
				$ip = $this->signs[$x."-".$y."-".$z."-".$level]["IP"];
				$port = (int)$this->signs[$x."-".$y."-".$z."-".$level]["Port"];
				if(!$this->transferPlayer($event->getPlayer(), $ip, $port)){
					$event->getPlayer()->sendMessage(TextFormat::RED . "跨服傳送出现错误");
				}
			}
		}
	}

	/**
	* 以下代码摘自shoghicp的FastTransfer, 略作改动
	**/

	private $lookup = [];
	/**
	 * Will transfer a connected player to another server.
	 * This will trigger PlayerTransferEvent
	 *
	 * Player transfer might not be instant if you use a DNS address instead of an IP address
	 *
	 * @param Player $player
	 * @param string $address
	 * @param int    $port
	 * @param string $message If null, ignore message
	 *
	 * @return bool
	 */
	public function transferPlayer(Player $player, $address, $port = 19132, $message = "你正在进行跨服傳送"){
		$ip = $this->lookupAddress($address);
		if($ip === null){
			return false;
		}
		$this->cleanupMemory($player);
		$packet = new StrangePacket();
		$packet->address = $ip;
		$packet->port = $port;
		$player->dataPacket($packet->setChannel(Network::CHANNEL_ENTITY_SPAWNING));
		return true;
	}
	/**
	 * Clear the DNS lookup cache.
	 */
	public function cleanLookupCache(){
		$this->lookup = [];
	}

	/**
	 * @param $address
	 *
	 * @return null|string
	 */
	private function lookupAddress($address){
		//IP address
		if(preg_match("/^[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}$/", $address) > 0){
			return $address;
		}
		$address = strtolower($address);
		if(isset($this->lookup[$address])){
			return $this->lookup[$address];
		}
		$host = gethostbyname($address);
		if($host === $address){
			return null;
		}
		$this->lookup[$address] = $host;
		return $host;
	}
	private function cleanupMemory(Player $player){
		foreach($player->usedChunks as $index => $c){
			Level::getXZ($index, $chunkX, $chunkZ);
			foreach($player->getLevel()->getChunkEntities($chunkX, $chunkZ) as $entity){
				if($entity !== $this){
					$entity->despawnFrom($player);
				}
			}
		}
	}
}
