<?php

namespace gpustat\lib;

use SimpleXMLElement;

/**
 * Class Nvidia
 * @package gpustat\lib
 */
class Nvidia extends Main
{
    const CMD_UTILITY = 'nvidia-smi';
    const INVENTORY_PARAM = '-L';
    const INVENTORY_REGEX = '/GPU\s(?P<id>\d):\s(?P<model>.*)\s\(UUID:\s(?P<guid>GPU-[0-9a-f-]+)\)/i';
    const STATISTICS_PARAM = '-q -x -i %s 2>&1';

    /**
     * Nvidia constructor.
     * @param array $settings
     */
    public function __construct(array $settings = [])
    {
        $settings += ['cmd' => self::CMD_UTILITY];
        parent::__construct($settings);
    }

    /**
     * Retrieves NVIDIA card inventory and parses into an array
     *
     * @return array
     */
    public function getInventory()
    {
        $result = [];

        if ($this->cmdexists) {
            $this->stdout = shell_exec(self::CMD_UTILITY . ES . self::INVENTORY_PARAM);
            if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                $this->parseInventory(self::INVENTORY_REGEX);
            } else {
                new Error(Error::VENDOR_DATA_NOT_RETURNED, '', false);
            }
            if ($this->cmdexists) {
                $result = $this->inventory;
            }
        }

        return $result;
    }

    /**
     * Retrieves NVIDIA card statistics
     */
    public function getStatistics()
    {
        if ($this->cmdexists) {
            //Command invokes nvidia-smi in query all mode with XML return
            $this->stdout = shell_exec(self::CMD_UTILITY . ES . sprintf(self::STATISTICS_PARAM, $this->settings['GPUID']));
            if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                $this->parseStatistics();
            } else {
                new Error(Error::VENDOR_DATA_NOT_RETURNED);
            }
        } else {
            new Error(Error::VENDOR_UTILITY_NOT_FOUND);
        }
    }

    /**
     * Loads stdout into SimpleXMLObject then retrieves and returns specific definitions in an array
     */
    private function parseStatistics()
    {

        $data = @simplexml_load_string($this->stdout);
        $retval = array();

        if ($data instanceof SimpleXMLElement && !empty($data->gpu)) {

            $gpu = $data->gpu;
            $retval = $this->pageData;
    
            $retval += [
                'vendor'    => 'NVIDIA',
                'name'      => 'Graphics Card',
            ];

            if (isset($gpu->product_name)) {
                $retval['name'] = (string) $gpu->product_name;
            }
            if (isset($gpu->utilization)) {
                $utils = [
                    'gpu_util'=>'util','memory_util'=>'memutil','encoder_util'=>'encutil','decoder_util'=>'decutil',
                ];
                foreach ($utils AS $key => $value) {
                    if (isset($gpu->utilization->$key)) {
                        $retval[$value] = (string) $this->stripSpaces($gpu->utilization->$key);
                    }
                }
            }
            if (isset($gpu->temperature)) {
                if (isset($gpu->temperature->gpu_temp)) {
                    $retval['temp'] = (string) $this->stripSpaces($gpu->temperature->gpu_temp);
                }
                if (isset($gpu->temperature->gpu_temp_max_threshold)) {
                    $retval['tempmax'] = (string) $this->stripSpaces($gpu->temperature->gpu_temp_max_threshold);
                }
                if ($this->settings['TEMPFORMAT'] == 'F') {
                    foreach (['temp', 'tempmax'] AS $key) {
                        $retval[$key] = $this->convertCelsius((int) str_replace('C', '', $retval[$key])) . 'F';
                    }
                }
            }
            if (isset($gpu->fan_speed)) {
                $retval['fan'] = (string) $this->stripSpaces($gpu->fan_speed);
            }
            if (isset($gpu->performance_state)) {
                $retval['perfstate'] = (string) $this->stripSpaces($gpu->performance_state);
            }
            if (isset($gpu->clocks_throttle_reasons)) {
                $retval['throttled'] = 'No';
                foreach ($gpu->clocks_throttle_reasons->children() AS $reason => $throttle) {
                    if ($throttle == 'Active') {
                        $retval['throttled'] = 'Yes';
                        $retval['thrtlrsn'] = ' (' . str_replace('clocks_throttle_reason_', '', $reason) . ')';
                        break;
                    }
                }
            }
            if (isset($gpu->power_readings)) {
                if (isset($gpu->power_readings->power_draw)) {
                    $retval['power'] = (string) $this->stripSpaces($gpu->power_readings->power_draw);
                }
                if (isset($gpu->power_readings->power_limit)) {
                    $retval['powermax'] = (string) str_replace('.00 ', '', $gpu->power_readings->power_limit);
                }
            }
            if (isset($gpu->clocks)) {
                if (isset($gpu->clocks->graphics_clock)) {
                    $retval['clock'] = (string) str_replace(' MHz', '', $gpu->clocks->graphics_clock);
                }
                if (isset($gpu->clocks->mem_clock)) {
                    $retval['memclock'] = (string) $gpu->clocks->mem_clock;
                }
            }
            // For some reason, encoder_sessions->session_count is not reliable on my install, better to count processes
            if (isset($gpu->processes) && isset($gpu->processes->process_info)) {
                $retval['sessions'] = (int) count($gpu->processes->process_info);
            }
        } else {
            new Error(Error::VENDOR_DATA_BAD_PARSE);
        }
        $this->echoJson($retval);
    }
}
