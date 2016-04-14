<?php

namespace softmine\utils;

use softmine\Thread;

class ServerKiller extends Thread{

	public $time;

	public function __construct($time = 15){
		$this->time = $time;
	}

	public function run(){
		sleep($this->time);
		echo "\nTook too long to stop, server was killed forcefully!\n";
		@\softmine\kill(getmypid());
	}

	public function getThreadName(){
		return "Server Killer";
	}
}
