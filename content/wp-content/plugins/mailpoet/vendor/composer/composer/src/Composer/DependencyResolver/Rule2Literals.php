<?php
namespace Composer\DependencyResolver;
if (!defined('ABSPATH')) exit;
class Rule2Literals extends Rule
{
 protected $literal1;
 protected $literal2;
 public function __construct($literal1, $literal2, $reason, $reasonData)
 {
 parent::__construct($reason, $reasonData);
 if ($literal1 < $literal2) {
 $this->literal1 = $literal1;
 $this->literal2 = $literal2;
 } else {
 $this->literal1 = $literal2;
 $this->literal2 = $literal1;
 }
 }
 public function getLiterals()
 {
 return array($this->literal1, $this->literal2);
 }
 public function getHash()
 {
 return $this->literal1.','.$this->literal2;
 }
 public function equals(Rule $rule)
 {
 // specialized fast-case
 if ($rule instanceof self) {
 if ($this->literal1 !== $rule->literal1) {
 return false;
 }
 if ($this->literal2 !== $rule->literal2) {
 return false;
 }
 return true;
 }
 $literals = $rule->getLiterals();
 if (2 != \count($literals)) {
 return false;
 }
 if ($this->literal1 !== $literals[0]) {
 return false;
 }
 if ($this->literal2 !== $literals[1]) {
 return false;
 }
 return true;
 }
 public function isAssertion()
 {
 return false;
 }
 public function __toString()
 {
 $result = $this->isDisabled() ? 'disabled(' : '(';
 $result .= $this->literal1 . '|' . $this->literal2 . ')';
 return $result;
 }
}
