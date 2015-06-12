<?php

/**
 * @file
 * Contains \Drupal\Tests\flysystem\Unit\Form\ConfigFormTest.
 */

namespace Drupal\Tests\flysystem\Unit\Form {

use Drupal\Component\Utility\SafeMarkup;
use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use Drupal\flysystem\Form\ConfigForm;
use League\Flysystem\Filesystem;
use Prophecy\Argument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Twistor\Flysystem\MemoryAdapter;

/**
 * @coversDefaultClass \Drupal\flysystem\Form\ConfigForm
 * @group flysystem
 */
class ConfigFormTest extends UnitTestCase {

  protected $factory;

  protected $form;

  public function setUp() {
    parent::setUp();

    $this->factory = $this->prophesize('Drupal\flysystem\FlysystemFactory');
    $this->factory->getFilesystem('from_empty')->willReturn(new Filesystem(new MemoryAdapter()));
    $this->factory->getFilesystem('to_empty')->willReturn(new Filesystem(new MemoryAdapter()));

    $this->form = new ConfigForm($this->factory->reveal());
    $this->form->setStringTranslation($this->getStringTranslationStub());
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('flysystem_factory', $this->factory->reveal());

    $logger = $this->prophesize('Drupal\Core\Logger\LoggerChannelFactoryInterface');
    $logger->get('flysystem')->willReturn($this->prophesize('Psr\Log\LoggerInterface')->reveal());
    $container->set('logger.factory', $logger->reveal());

    \Drupal::setContainer($container);
  }

  /**
   * @covers ::create
   * @covers ::__construct
   */
  public function testCreate() {
    $container = new ContainerBuilder();
    $container->set('flysystem_factory', $this->factory->reveal());

    $this->assertInstanceOf('Drupal\flysystem\Form\ConfigForm', ConfigForm::create($container));
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertSame('flysystem_config_form', $this->form->getFormId());
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildForm() {
    $form = $this->form->buildForm([], new FormState());
    $this->assertSame(4, count($form));
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateForm() {
    $form_state = new FormState();
    $form = $this->form->buildForm([], $form_state);
    $form['sync_from']['#parents'] = ['sync_from'];
    $form['sync_to']['#parents'] = ['sync_to'];

    $form_state->setValue('sync_from', 'from');
    $form_state->setValue('sync_to', 'to');

    $this->form->validateForm($form, $form_state);
    $this->assertSame(0, count($form_state->getErrors()));

    $form_state->setValue('sync_to', 'from');

    $this->form->validateForm($form, $form_state);
    $this->assertSame(2, count($form_state->getErrors()));
  }

  /**
   * @covers ::submitForm
   * @covers ::getFileList
   */
  public function testSubmitForm() {
    $form_state = new FormState();
    $form = [];
    $form_state->setValue('sync_from', 'from_empty');
    $form_state->setValue('sync_to', 'to_empty');

    $this->form->submitForm($form, $form_state);
    $batch = batch_set();
    $this->assertSame('Drupal\flysystem\Form\ConfigForm::finishBatch', $batch['finished']);
    $this->assertSame(0, count($batch['operations']));

    // Test with existing source files.
    $from = new Filesystem(new MemoryAdapter());
    $from->write('dir/test.txt', 'abcdefg');
    $from->write('test.txt', 'abcdefg');
    $this->factory->getFilesystem('from_files')->willReturn($from);

    $form_state->setValue('sync_from', 'from_files');

    $this->form->submitForm($form, $form_state);

    $batch_files = array_map(function (array $operation) {
      return $operation[1][2];
    }, batch_set()['operations']);

    $this->assertSame(['dir/test.txt', 'test.txt'], $batch_files);

    // Test with existing destination files, and force true.
    $form_state->setValue('force', TRUE);
    $form_state->setValue('sync_to', 'from_files');

    $this->form->submitForm($form, $form_state);

    $batch_files = array_map(function (array $operation) {
      return $operation[1][2];
    }, batch_set()['operations']);

    $this->assertSame(['dir/test.txt', 'test.txt'], $batch_files);
  }

  /**
   * @covers ::copyFile
   */
  public function testCopyFile() {
    $context = [];

    $from = new Filesystem(new MemoryAdapter());
    $from->write('dir/test.txt', 'abcdefg');
    $this->factory->getFilesystem('from_files')->willReturn($from);

    ConfigForm::copyFile('from_files', 'to_empty', 'dir/test.txt', $context);

    $this->assertSame('abcdefg', $this->factory->reveal()->getFilesystem('to_empty')->read('dir/test.txt'));
    $this->assertTrue(empty($context['results']));
  }

  /**
   * @covers ::copyFile
   */
  public function testCopyFileFailedRead() {
    // Tests failed read.
    $context = [];
    $failed_read = $this->prophesize('League\Flysystem\FilesystemInterface');
    $failed_read->readStream('does_not_exist')->willReturn(FALSE);
    $this->factory->getFilesystem('failed_read')->willReturn($failed_read->reveal());

    ConfigForm::copyFile('failed_read', 'to_empty', 'does_not_exist', $context);

    $to_files = $this->factory->reveal()->getFilesystem('to_empty')->listContents('', TRUE);
    $this->assertSame(0, count($to_files));
    $this->assertSame(1, count($context['results']['errors']));
  }

  /**
   * @covers ::copyFile
   */
  public function testCopyFileFailedWrite() {
    $context = [];

    $from = new Filesystem(new MemoryAdapter());
    $from->write('test.txt', 'abcdefg');
    $this->factory->getFilesystem('from_files')->willReturn($from);

    $failed_write = $this->prophesize('League\Flysystem\FilesystemInterface');
    $failed_write->putStream(Argument::cetera())->willReturn(FALSE);
    $this->factory->getFilesystem('to_fail')->willReturn($failed_write);

    ConfigForm::copyFile('from_files', 'to_fail', 'test.txt', $context);

    $this->assertSame(1, count($context['results']['errors']));
    $this->assertTrue(strpos($context['results']['errors'][0][0], 'could not be saved') !== FALSE);
  }

  /**
   * @covers ::copyFile
   */
  public function testCopyFileException() {
    $context = [];
    ConfigForm::copyFile('from_empty', 'to_empty', 'does_not_exist.txt', $context);
    $this->assertSame(2, count($context['results']['errors']));
    $this->assertTrue(strpos($context['results']['errors'][0][0], 'An eror occured while copying') !== FALSE);
    $this->assertTrue(strpos($context['results']['errors'][1], 'File not found at path') !== FALSE);
  }

  /**
   * @covers ::finishBatch
   */
  public function testFinishBatch() {
    ConfigForm::finishBatch(TRUE, [], []);
    ConfigForm::finishBatch(FALSE, [], ['from', 'to', 'file.txt']);
    ConfigForm::finishBatch(TRUE, ['errors' => ['first error', ['second error', ['']]]], []);
  }

  protected function getFileList(array $list) {
    $list = array_filter($list, function (array $file) {
      return $file['type'] === 'file';
    });

    return array_map(function (array $file) {
      return $file['path'];
    }, $list);
  }

}
}

namespace {
  if (!function_exists('drupal_set_messge')) {
    function drupal_set_message() {}
  }

  if (!function_exists('batch_set')) {
    function batch_set($batch = NULL) {
      static $last_batch;

      if (isset($batch)) {
        $last_batch = $batch;
      }
      return $last_batch;
    }
  }

  if (!function_exists('drupal_set_time_limit')) {
    function drupal_set_time_limit() {

    }
  }

  if (!function_exists('watchdog_exception')) {
    function watchdog_exception() {

    }
  }
}
