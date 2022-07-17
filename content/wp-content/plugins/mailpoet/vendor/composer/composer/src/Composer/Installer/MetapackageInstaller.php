<?php
namespace Composer\Installer;
if (!defined('ABSPATH')) exit;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\IO\IOInterface;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
class MetapackageInstaller implements InstallerInterface
{
 private $io;
 public function __construct(IOInterface $io)
 {
 $this->io = $io;
 }
 public function supports($packageType)
 {
 return $packageType === 'metapackage';
 }
 public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
 {
 return $repo->hasPackage($package);
 }
 public function download(PackageInterface $package, PackageInterface $prevPackage = null)
 {
 // noop
 return \React\Promise\resolve();
 }
 public function prepare($type, PackageInterface $package, PackageInterface $prevPackage = null)
 {
 // noop
 return \React\Promise\resolve();
 }
 public function cleanup($type, PackageInterface $package, PackageInterface $prevPackage = null)
 {
 // noop
 return \React\Promise\resolve();
 }
 public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
 {
 $this->io->writeError(" - " . InstallOperation::format($package));
 $repo->addPackage(clone $package);
 return \React\Promise\resolve();
 }
 public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
 {
 if (!$repo->hasPackage($initial)) {
 throw new \InvalidArgumentException('Package is not installed: '.$initial);
 }
 $this->io->writeError(" - " . UpdateOperation::format($initial, $target));
 $repo->removePackage($initial);
 $repo->addPackage(clone $target);
 return \React\Promise\resolve();
 }
 public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
 {
 if (!$repo->hasPackage($package)) {
 throw new \InvalidArgumentException('Package is not installed: '.$package);
 }
 $this->io->writeError(" - " . UninstallOperation::format($package));
 $repo->removePackage($package);
 return \React\Promise\resolve();
 }
 public function getInstallPath(PackageInterface $package)
 {
 return '';
 }
}
