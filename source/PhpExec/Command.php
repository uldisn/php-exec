<?php

namespace PhpExec;


use Closure;
use Evenement\EventEmitter;

class Command {

    /** @var string */
    private $_command;

    /** @var string[] */
    private $_arguments;

    /** @var EventEmitter */
    private $_eventEmitter;

    /**
     * @param string   $command
     * @param string[] $arguments
     */
    public function __construct($command, array $arguments = null) {
        $this->_command = (string) $command;
        $this->_arguments = (array) $arguments;
        $this->_eventEmitter = new EventEmitter();
    }

    /**
     * @param string   $event
     * @param Closure $callback
     */
    public function on($event, Closure $callback): void
    {
        $this->_eventEmitter->on($event, $callback);
    }

    /**
     * @param string|null $input
     * @throws Exception
     * @return Result
     */
    public function run($input = null): Result
    {
        $command = $this->_getCommand();
        $descriptorSpec = [
            0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]
        ];

        $cwd = NULL;
        $env = NULL;
        $options = NULL;
        if($this->isWindows()) {
            $options = ['bypass_shell' => true];
        }
        $process = proc_open($command, $descriptorSpec, $pipes, $cwd, $env, $options);
        if (!is_resource($process)) {
            throw new Exception('Cannot open command file pointer to `' . $command . '`');
        }
        $processStatus = proc_get_status($process);
        $this->_eventEmitter->emit('start', [$processStatus['pid']]);

        if (null !== $input) {
            fwrite($pipes[0], (string) $input);
        }
        fclose($pipes[0]);

        $stdout = null;
        $stderr = null;
        do {
            $readPipes = [$pipes[1], $pipes[2]];
            $writePipes = [];
            $exceptPipes = [];
            stream_select($readPipes, $writePipes, $exceptPipes, null);

            foreach ($readPipes as $readPipe) {
                $content = fread($readPipe, 4096);
                $streamType = array_search($readPipe, $pipes);
                switch ($streamType) {
                    case 1:
                        $stdout .= $content;
                        $this->_eventEmitter->emit('stdout', [$content]);
                        break;
                    case 2:
                        $stderr .= $content;
                        $this->_eventEmitter->emit('stderr', [$content]);
                        break;
                }
            }
            $processStatus = proc_get_status($process);
        } while ($processStatus['running']);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = $processStatus['exitcode'];
        $this->_eventEmitter->emit('stop', [$exitCode]);
        return new Result($this, $exitCode, $stdout, $stderr);
    }

    /**
     * @return string
     */
    protected function _getCommand(): string
    {
        if(!$this->isWindows()) {
            $command = escapeshellcmd($this->_command);
            foreach ($this->_arguments as $argument) {
                $command .= ' ' . escapeshellarg($argument);
            }
            return $command;
        }
        return '"' . str_replace('\\','\\\\',$this->_command) .'" ' . implode(' ',$this->_arguments);
    }

    private function isWindows(): bool
    {
        return stripos(strtoupper(PHP_OS), 'WIN') === 0;
    }
}
