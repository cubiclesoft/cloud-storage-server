<?php
	// Event manager class.
	// (C) 2018 CubicleSoft.  All Rights Reserved.

	class EventManager
	{
		private $events, $nextid;

		public function __construct()
		{
			$this->events = array();
			$this->nextid = 1;
		}

		public function Register($eventname, $objorfuncname, $funcname = false)
		{
			if ($objorfuncname === false)
			{
				$objorfuncname = $funcname;
				$funcname = false;
			}

			if (!isset($this->events[$eventname]))  $this->events[$eventname] = array("used" => 0, "callbacks" => array());
			$this->events[$eventname]["callbacks"][$this->nextid] = ($funcname === false ? $objorfuncname : array($obj, $funcname));

			$id = $this->nextid;
			$this->nextid++;

			return $id;
		}

		public function Unregister($eventname, $id)
		{
			if (isset($this->events[$eventname]))  unset($this->events[$eventname]["callbacks"][$id]);
		}

		public function GetAllUsedCounts()
		{
			$result = array();
			foreach ($this->events as $eventname => $info)  $result[$eventname] = $info["used"];

			return $result;
		}

		public function GetUsedCount($eventname)
		{
			return (isset($this->events[$eventname]) ? $this->events[$eventname]["used"] : 0);
		}

		public function Fire($eventname, $options)
		{
			$results = array();
			if ($eventname !== "" && isset($this->events[$eventname]))
			{
				foreach ($this->events[$eventname]["callbacks"] as $id => $func)
				{
					if (!is_callable($func))  unset($this->events[$eventname]["callbacks"][$id]);
					else
					{
						$result = call_user_func_array($func, $options);
						if (isset($result))  $results[] = $result;
					}
				}

				if (count($this->events[$eventname]["callbacks"]))  $this->events[$eventname]["used"]++;
				else  unset($this->events[$eventname]);
			}

			if (isset($this->events[""]))
			{
				foreach ($this->events[""]["callbacks"] as $id => $func)
				{
					if (!is_callable($func))  unset($this->events[""]["callbacks"][$id]);
					else
					{
						$result = call_user_func_array($func, array_merge(array($eventname), $options));
						if (isset($result))  $results[] = $result;
					}
				}

				if (count($this->events[""]["callbacks"]))  $this->events[""]["used"]++;
				else  unset($this->events[""]);
			}

			return $results;
		}
	}
?>