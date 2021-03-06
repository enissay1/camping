<?php
namespace Composer\DependencyResolver;
if (!defined('ABSPATH')) exit;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Version\VersionParser;
use Composer\Semver\CompilingMatcher;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Intervals;
class PoolOptimizer
{
 private $policy;
 private $irremovablePackages = array();
 private $requireConstraintsPerPackage = array();
 private $conflictConstraintsPerPackage = array();
 private $packagesToRemove = array();
 private $aliasesPerPackage = array();
 private $removedVersionsByPackage = array();
 public function __construct(PolicyInterface $policy)
 {
 $this->policy = $policy;
 }
 public function optimize(Request $request, Pool $pool)
 {
 $this->prepare($request, $pool);
 $this->optimizeByIdenticalDependencies($request, $pool);
 $this->optimizeImpossiblePackagesAway($request, $pool);
 $optimizedPool = $this->applyRemovalsToPool($pool);
 // No need to run this recursively at the moment
 // because the current optimizations cannot provide
 // even more gains when ran again. Might change
 // in the future with additional optimizations.
 $this->irremovablePackages = array();
 $this->requireConstraintsPerPackage = array();
 $this->conflictConstraintsPerPackage = array();
 $this->packagesToRemove = array();
 $this->aliasesPerPackage = array();
 $this->removedVersionsByPackage = array();
 return $optimizedPool;
 }
 private function prepare(Request $request, Pool $pool)
 {
 $irremovablePackageConstraintGroups = array();
 // Mark fixed or locked packages as irremovable
 foreach ($request->getFixedOrLockedPackages() as $package) {
 $irremovablePackageConstraintGroups[$package->getName()][] = new Constraint('==', $package->getVersion());
 }
 // Extract requested package requirements
 foreach ($request->getRequires() as $require => $constraint) {
 $this->extractRequireConstraintsPerPackage($require, $constraint);
 }
 // First pass over all packages to extract information and mark package constraints irremovable
 foreach ($pool->getPackages() as $package) {
 // Extract package requirements
 foreach ($package->getRequires() as $link) {
 $this->extractRequireConstraintsPerPackage($link->getTarget(), $link->getConstraint());
 }
 // Extract package conflicts
 foreach ($package->getConflicts() as $link) {
 $this->extractConflictConstraintsPerPackage($link->getTarget(), $link->getConstraint());
 }
 // Keep track of alias packages for every package so if either the alias or aliased is kept
 // we keep the others as they are a unit of packages really
 if ($package instanceof AliasPackage) {
 $this->aliasesPerPackage[$package->getAliasOf()->id][] = $package;
 }
 }
 $irremovablePackageConstraints = array();
 foreach ($irremovablePackageConstraintGroups as $packageName => $constraints) {
 $irremovablePackageConstraints[$packageName] = 1 === \count($constraints) ? $constraints[0] : new MultiConstraint($constraints, false);
 }
 unset($irremovablePackageConstraintGroups);
 // Mark the packages as irremovable based on the constraints
 foreach ($pool->getPackages() as $package) {
 if (!isset($irremovablePackageConstraints[$package->getName()])) {
 continue;
 }
 if (CompilingMatcher::match($irremovablePackageConstraints[$package->getName()], Constraint::OP_EQ, $package->getVersion())) {
 $this->markPackageIrremovable($package);
 }
 }
 }
 private function markPackageIrremovable(BasePackage $package)
 {
 $this->irremovablePackages[$package->id] = true;
 if ($package instanceof AliasPackage) {
 // recursing here so aliasesPerPackage for the aliasOf can be checked
 // and all its aliases marked as irremovable as well
 $this->markPackageIrremovable($package->getAliasOf());
 }
 if (isset($this->aliasesPerPackage[$package->id])) {
 foreach ($this->aliasesPerPackage[$package->id] as $aliasPackage) {
 $this->irremovablePackages[$aliasPackage->id] = true;
 }
 }
 }
 private function applyRemovalsToPool(Pool $pool)
 {
 $packages = array();
 $removedVersions = array();
 foreach ($pool->getPackages() as $package) {
 if (!isset($this->packagesToRemove[$package->id])) {
 $packages[] = $package;
 } else {
 $removedVersions[$package->getName()][$package->getVersion()] = $package->getPrettyVersion();
 }
 }
 $optimizedPool = new Pool($packages, $pool->getUnacceptableFixedOrLockedPackages(), $removedVersions, $this->removedVersionsByPackage);
 return $optimizedPool;
 }
 private function optimizeByIdenticalDependencies(Request $request, Pool $pool)
 {
 $identicalDefinitionsPerPackage = array();
 $packageIdenticalDefinitionLookup = array();
 foreach ($pool->getPackages() as $package) {
 // If that package was already marked irremovable, we can skip
 // the entire process for it
 if (isset($this->irremovablePackages[$package->id])) {
 continue;
 }
 $this->markPackageForRemoval($package->id);
 $dependencyHash = $this->calculateDependencyHash($package);
 foreach ($package->getNames(false) as $packageName) {
 if (!isset($this->requireConstraintsPerPackage[$packageName])) {
 continue;
 }
 foreach ($this->requireConstraintsPerPackage[$packageName] as $requireConstraint) {
 $groupHashParts = array();
 if (CompilingMatcher::match($requireConstraint, Constraint::OP_EQ, $package->getVersion())) {
 $groupHashParts[] = 'require:' . (string) $requireConstraint;
 }
 if ($package->getReplaces()) {
 foreach ($package->getReplaces() as $link) {
 if (CompilingMatcher::match($link->getConstraint(), Constraint::OP_EQ, $package->getVersion())) {
 // Use the same hash part as the regular require hash because that's what the replacement does
 $groupHashParts[] = 'require:' . (string) $link->getConstraint();
 }
 }
 }
 if (isset($this->conflictConstraintsPerPackage[$packageName])) {
 foreach ($this->conflictConstraintsPerPackage[$packageName] as $conflictConstraint) {
 if (CompilingMatcher::match($conflictConstraint, Constraint::OP_EQ, $package->getVersion())) {
 $groupHashParts[] = 'conflict:' . (string) $conflictConstraint;
 }
 }
 }
 if (!$groupHashParts) {
 continue;
 }
 $groupHash = implode('', $groupHashParts);
 $identicalDefinitionsPerPackage[$packageName][$groupHash][$dependencyHash][] = $package;
 $packageIdenticalDefinitionLookup[$package->id][$packageName] = array('groupHash' => $groupHash, 'dependencyHash' => $dependencyHash);
 }
 }
 }
 foreach ($identicalDefinitionsPerPackage as $constraintGroups) {
 foreach ($constraintGroups as $constraintGroup) {
 foreach ($constraintGroup as $packages) {
 // Only one package in this constraint group has the same requirements, we're not allowed to remove that package
 if (1 === \count($packages)) {
 $this->keepPackage($packages[0], $identicalDefinitionsPerPackage, $packageIdenticalDefinitionLookup);
 continue;
 }
 // Otherwise we find out which one is the preferred package in this constraint group which is
 // then not allowed to be removed either
 $literals = array();
 foreach ($packages as $package) {
 $literals[] = $package->id;
 }
 foreach ($this->policy->selectPreferredPackages($pool, $literals) as $preferredLiteral) {
 $this->keepPackage($pool->literalToPackage($preferredLiteral), $identicalDefinitionsPerPackage, $packageIdenticalDefinitionLookup);
 }
 }
 }
 }
 }
 private function calculateDependencyHash(BasePackage $package)
 {
 $hash = '';
 $hashRelevantLinks = array(
 'requires' => $package->getRequires(),
 'conflicts' => $package->getConflicts(),
 'replaces' => $package->getReplaces(),
 'provides' => $package->getProvides()
 );
 foreach ($hashRelevantLinks as $key => $links) {
 if (0 === \count($links)) {
 continue;
 }
 // start new hash section
 $hash .= $key . ':';
 $subhash = array();
 foreach ($links as $link) {
 // To get the best dependency hash matches we should use Intervals::compactConstraint() here.
 // However, the majority of projects are going to specify their constraints already pretty
 // much in the best variant possible. In other words, we'd be wasting time here and it would actually hurt
 // performance more than the additional few packages that could be filtered out would benefit the process.
 $subhash[$link->getTarget()] = (string) $link->getConstraint();
 }
 // Sort for best result
 ksort($subhash);
 foreach ($subhash as $target => $constraint) {
 $hash .= $target . '@' . $constraint;
 }
 }
 return $hash;
 }
 private function markPackageForRemoval($id)
 {
 // We are not allowed to remove packages if they have been marked as irremovable
 if (isset($this->irremovablePackages[$id])) {
 throw new \LogicException('Attempted removing a package which was previously marked irremovable');
 }
 $this->packagesToRemove[$id] = true;
 }
 private function keepPackage(BasePackage $package, $identicalDefinitionsPerPackage, $packageIdenticalDefinitionLookup)
 {
 // Already marked to keep
 if (!isset($this->packagesToRemove[$package->id])) {
 return;
 }
 unset($this->packagesToRemove[$package->id]);
 if ($package instanceof AliasPackage) {
 // recursing here so aliasesPerPackage for the aliasOf can be checked
 // and all its aliases marked to be kept as well
 $this->keepPackage($package->getAliasOf(), $identicalDefinitionsPerPackage, $packageIdenticalDefinitionLookup);
 }
 // record all the versions of the package group so we can list them later in Problem output
 foreach ($package->getNames(false) as $name) {
 if (isset($packageIdenticalDefinitionLookup[$package->id][$name])) {
 $packageGroupPointers = $packageIdenticalDefinitionLookup[$package->id][$name];
 $packageGroup = $identicalDefinitionsPerPackage[$name][$packageGroupPointers['groupHash']][$packageGroupPointers['dependencyHash']];
 foreach ($packageGroup as $pkg) {
 if ($pkg instanceof AliasPackage && $pkg->getPrettyVersion() === VersionParser::DEFAULT_BRANCH_ALIAS) {
 $pkg = $pkg->getAliasOf();
 }
 $this->removedVersionsByPackage[spl_object_hash($package)][$pkg->getVersion()] = $pkg->getPrettyVersion();
 }
 }
 }
 if (isset($this->aliasesPerPackage[$package->id])) {
 foreach ($this->aliasesPerPackage[$package->id] as $aliasPackage) {
 unset($this->packagesToRemove[$aliasPackage->id]);
 // record all the versions of the package group so we can list them later in Problem output
 foreach ($aliasPackage->getNames(false) as $name) {
 if (isset($packageIdenticalDefinitionLookup[$aliasPackage->id][$name])) {
 $packageGroupPointers = $packageIdenticalDefinitionLookup[$aliasPackage->id][$name];
 $packageGroup = $identicalDefinitionsPerPackage[$name][$packageGroupPointers['groupHash']][$packageGroupPointers['dependencyHash']];
 foreach ($packageGroup as $pkg) {
 if ($pkg instanceof AliasPackage && $pkg->getPrettyVersion() === VersionParser::DEFAULT_BRANCH_ALIAS) {
 $pkg = $pkg->getAliasOf();
 }
 $this->removedVersionsByPackage[spl_object_hash($aliasPackage)][$pkg->getVersion()] = $pkg->getPrettyVersion();
 }
 }
 }
 }
 }
 }
 private function optimizeImpossiblePackagesAway(Request $request, Pool $pool)
 {
 if (count($request->getLockedPackages()) === 0) {
 return;
 }
 $packageIndex = array();
 foreach ($pool->getPackages() as $package) {
 $id = $package->id;
 // Do not remove irremovable packages
 if (isset($this->irremovablePackages[$id])) {
 continue;
 }
 // Do not remove a package aliased by another package, nor aliases
 if (isset($this->aliasesPerPackage[$id]) || $package instanceof AliasPackage) {
 continue;
 }
 // Do not remove locked packages
 if ($request->isFixedPackage($package) || $request->isLockedPackage($package)) {
 continue;
 }
 $packageIndex[$package->getName()][$package->id] = $package;
 }
 foreach ($request->getLockedPackages() as $package) {
 // If this locked package is no longer required by root or anything in the pool, it may get uninstalled so do not apply its requirements
 // In a case where a requirement WERE to appear in the pool by a package that would not be used, it would've been unlocked and so not filtered still
 $isUnusedPackage = true;
 foreach ($package->getNames(false) as $packageName) {
 if (isset($this->requireConstraintsPerPackage[$packageName])) {
 $isUnusedPackage = false;
 break;
 }
 }
 if ($isUnusedPackage) {
 continue;
 }
 foreach ($package->getRequires() as $link) {
 $require = $link->getTarget();
 if (!isset($packageIndex[$require])) {
 continue;
 }
 $linkConstraint = $link->getConstraint();
 foreach ($packageIndex[$require] as $id => $requiredPkg) {
 if (false === CompilingMatcher::match($linkConstraint, Constraint::OP_EQ, $requiredPkg->getVersion())) {
 $this->markPackageForRemoval($id);
 unset($packageIndex[$require][$id]);
 }
 }
 }
 }
 }
 private function extractRequireConstraintsPerPackage($package, ConstraintInterface $constraint)
 {
 foreach ($this->expandDisjunctiveMultiConstraints($constraint) as $expanded) {
 $this->requireConstraintsPerPackage[$package][(string) $expanded] = $expanded;
 }
 }
 private function extractConflictConstraintsPerPackage($package, ConstraintInterface $constraint)
 {
 foreach ($this->expandDisjunctiveMultiConstraints($constraint) as $expanded) {
 $this->conflictConstraintsPerPackage[$package][(string) $expanded] = $expanded;
 }
 }
 private function expandDisjunctiveMultiConstraints(ConstraintInterface $constraint)
 {
 $constraint = Intervals::compactConstraint($constraint);
 if ($constraint instanceof MultiConstraint && $constraint->isDisjunctive()) {
 // No need to call ourselves recursively here because Intervals::compactConstraint() ensures that there
 // are no nested disjunctive MultiConstraint instances possible
 return $constraint->getConstraints();
 }
 // Regular constraints and conjunctive MultiConstraints
 return array($constraint);
 }
}
