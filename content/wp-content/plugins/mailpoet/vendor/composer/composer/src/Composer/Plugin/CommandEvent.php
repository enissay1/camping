<?php
namespace Composer\Plugin;
if (!defined('ABSPATH')) exit;
use Composer\EventDispatcher\Event;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
class CommandEvent extends Event
{
 private $commandName;
 private $input;
 private $output;
 public function __construct($name, $commandName, $input, $output, array $args = array(), array $flags = array())
 {
 parent::__construct($name, $args, $flags);
 $this->commandName = $commandName;
 $this->input = $input;
 $this->output = $output;
 }
 public function getInput()
 {
 return $this->input;
 }
 public function getOutput()
 {
 return $this->output;
 }
 public function getCommandName()
 {
 return $this->commandName;
 }
}
