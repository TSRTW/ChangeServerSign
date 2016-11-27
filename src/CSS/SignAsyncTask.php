<?php

namespace InterServerSign;

use pocketmine\Server;
use pocketmine\scheduler\AsyncTask;
use pocketmine\math\Vector3;
use pocketmine\utils\TextFormat;

class SignAsyncTask extends AsyncTask{

	private $ip, $port, $x, $y, $z, $level;

	public function setDate($ip, $port, $x, $y, $z, $level){
		$this->ip = $ip;
		$this->port = $port;
		$this->x = $x;
		$this->y = $y;
		$this->z = $z;
		$this->level = $level;
	}

	public function onRun(){
		$re = [];
		$client = @stream_socket_client("udp://".$this->ip.":".$this->port, $errno, $errstr);	//非阻塞Socket
		if(!$client){
		    $re = array(
					"status" => 0,
					"name" => "",
					"playernum" => "*/*",
				);
		}else{
			stream_set_timeout($client , 1);
			$Handshake_to = "\xFE\xFD".chr(9).pack("N",233);
		    fwrite($client, $Handshake_to);
		    $Handshake_re_1 = fread($client, 65535);
		    if ($Handshake_re_1 != "") {
		    	$Handshake_re = $this->decode($Handshake_re_1);
		    	$Status_to = "\xFE\xFD".chr(0).pack("N",233).pack("N",$Handshake_re["payload"]);
		    	fwrite($client, $Status_to);
		    	$Status_re_1 = fread($client, 65535);
		    	if ($Status_re_1 != "") {
		    		$Status_re = $this->decode($Status_re_1);
			   	 	$ServerData = explode("\x00",$Status_re["payload"]);
			    	$re = array(
							"status" => 1,
							"name" => $ServerData[0],
							"playernum" => $ServerData[3]."/".$ServerData[4],
						);
		    	}else{
		    		$re = array(
							"status" => 0,
							"name" => "",
							"playernum" => "*/*",
						);
		    	}
		    }else{
		    	$re = array(
						"status" => 0,
						"name" => "",
						"playernum" => "*/*",
					);

		    }
		    fclose($client);
		}
		$this->setResult($re, false);
	}

	/**
	 * Actions to execute when completed (on main thread)
	 * Implement this if you want to handle the data in your AsyncTask after it has been processed
	 *
	 * @param Server $server
	 *
	 * @return void
	 */
	public function onCompletion(Server $server){
		$re = $this->getResult();
		$status = $re["status"] == 1 ? TextFormat::GREEN."Online" : TextFormat::RED."Offline";
		$name = $re["name"] == "" ? $this->ip.":".$this->port : $re["name"];
		$playernum = TextFormat::WHITE.$re["playernum"];
		//$server->broadcastMessage(TextFormat::DARK_GREEN . "*Get ".$status." ".$name." ".$playernum);
		$sign = $server->getLevelByName($this->level)->getTile(new Vector3($this->x,$this->y,$this->z));
		if ($sign != null) {
			$sign->setText(TextFormat::DARK_GREEN . "[跨服传送]", $status, $name, $playernum);
		}
	}

	public function decode($buffer){
		$redata = [];
		$redata["packetType"] = ord($buffer{0});
		$redata["sessionID"] = unpack("N",substr($buffer, 1, 4))[1];
		$redata["payload"] = rtrim(substr($buffer, 5));
		return $redata;
	}

}