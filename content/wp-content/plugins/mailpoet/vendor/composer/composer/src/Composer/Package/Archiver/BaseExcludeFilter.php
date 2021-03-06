<?php
namespace Composer\Package\Archiver;
if (!defined('ABSPATH')) exit;
use Composer\Pcre\Preg;
use Symfony\Component\Finder;
abstract class BaseExcludeFilter
{
 protected $sourcePath;
 protected $excludePatterns;
 public function __construct($sourcePath)
 {
 $this->sourcePath = $sourcePath;
 $this->excludePatterns = array();
 }
 public function filter($relativePath, $exclude)
 {
 foreach ($this->excludePatterns as $patternData) {
 list($pattern, $negate, $stripLeadingSlash) = $patternData;
 if ($stripLeadingSlash) {
 $path = substr($relativePath, 1);
 } else {
 $path = $relativePath;
 }
 try {
 if (Preg::isMatch($pattern, $path)) {
 $exclude = !$negate;
 }
 } catch (\RuntimeException $e) {
 // suppressed
 }
 }
 return $exclude;
 }
 protected function parseLines(array $lines, $lineParser)
 {
 return array_filter(
 array_map(
 function ($line) use ($lineParser) {
 $line = trim($line);
 if (!$line || 0 === strpos($line, '#')) {
 return null;
 }
 return call_user_func($lineParser, $line);
 },
 $lines
 ),
 function ($pattern) {
 return $pattern !== null;
 }
 );
 }
 protected function generatePatterns($rules)
 {
 $patterns = array();
 foreach ($rules as $rule) {
 $patterns[] = $this->generatePattern($rule);
 }
 return $patterns;
 }
 protected function generatePattern($rule)
 {
 $negate = false;
 $pattern = '';
 if ($rule !== '' && $rule[0] === '!') {
 $negate = true;
 $rule = ltrim($rule, '!');
 }
 $firstSlashPosition = strpos($rule, '/');
 if (0 === $firstSlashPosition) {
 $pattern = '^/';
 } elseif (false === $firstSlashPosition || strlen($rule) - 1 === $firstSlashPosition) {
 $pattern = '/';
 }
 $rule = trim($rule, '/');
 // remove delimiters as well as caret (^) and dollar sign ($) from the regex
 $rule = substr(Finder\Glob::toRegex($rule), 2, -2);
 return array('{'.$pattern.$rule.'(?=$|/)}', $negate, false);
 }
}
