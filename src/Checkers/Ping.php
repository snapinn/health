<?php

namespace PragmaRX\Health\Checkers;

use JJG\Ping as JJPing;

class Ping extends Base
{
    private $host;
    private $ttl;
    private $timeout;
    private $commandOutput;
    private $pingBin;

    /**
     * Check resource.
     *
     * @return bool
     */
    public function check()
    {
        $this->pingBin = $this->resource['binary'] ?? 'ping';

        foreach ($this->resource['servers'] as $data) {
            $ipAddress = ip_address_from_hostname($data['hostname']);

            $latency = $this->ping($ipAddress);

            info("latency: $ipAddress - $latency");

            if ($latency === false || $latency > $data['accepted_latency']) {
                return $this->makeResult(
                    false,
                    sprintf($this->resource['error_message'],
                        $this->hosnameAndIp($data['hostname'], $ipAddress), $latency === false ? '[ping error]' : $latency, $data['accepted_latency'])
                );
            }
        }

        return $this->makeHealthyResult();
    }

    public function ping($hostname, $timeout = 5, $ttl = 128)
    {
        $this->host = $hostname;
        $this->ttl = $ttl;
        $this->timeout = $timeout;

        return $this->pingExec();
    }

    /**
     * @param $hostname
     * @return mixed
     */
    protected function hosnameAndIp($hostname, $ipAdress)
    {
        return $hostname.($hostname != $ipAdress ? " ({$ipAdress})" : '');
    }

    /**
     * The exec method uses the possibly insecure exec() function, which passes
     * the input to the system. This is potentially VERY dangerous if you pass in
     * any user-submitted data. Be SURE you sanitize your inputs!
     *
     * @return int
     *   Latency, in ms.
     */
    private function pingExec()
    {
        $latency = false;

        $ttl = escapeshellcmd($this->ttl);
        $timeout = escapeshellcmd($this->timeout);
        $host = escapeshellcmd($this->host);

        // Exec string for Windows-based systems.
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // -n = number of pings; -i = ttl; -w = timeout (in milliseconds).
            $exec_string =
                $this->pingBin.' -n 1 -i ' .
                $ttl .
                ' -w ' .
                ($timeout * 1000) .
                ' ' .
                $host;
        } elseif (strtoupper(PHP_OS) === 'DARWIN') {
            // Exec string for Darwin based systems (OS X).
            // -n = numeric output; -c = number of pings; -m = ttl; -t = timeout.
            $exec_string =
                $this->pingBin.' -n -c 1 -m ' . $ttl . ' -t ' . $timeout . ' ' . $host;
        } else {
            // Exec string for other UNIX-based systems (Linux).
            // -n = numeric output; -c = number of pings; -t = ttl; -W = timeout
            $exec_string =
                $this->pingBin.' -n -c 1 -t ' .
                $ttl .
                ' -W ' .
                $timeout .
                ' ' .
                $host .
                ' 2>&1';
        }

        exec($exec_string, $output, $return);

        // Strip empty lines and reorder the indexes from 0 (to make results more
        // uniform across OS versions).
        $this->commandOutput = implode($output, '');
        $output = array_values(array_filter($output));

        // If the result line in the output is not empty, parse it.
        if (!empty($output[1])) {
            // Search for a 'time' value in the result line.
            $response = preg_match(
                "/time(?:=|<)(?<time>[\.0-9]+)(?:|\s)ms/",
                $output[1],
                $matches
            );

            // If there's a result and it's greater than 0, return the latency.
            if ($response > 0 && isset($matches['time'])) {
                $latency = round($matches['time']);
            }
        }

        return $latency;
    }
}