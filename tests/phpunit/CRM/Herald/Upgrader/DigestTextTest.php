<?php

declare(strict_types = 1);

use Civi\Herald\Digest\Definition;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * Digests that point at a message template take over its subject and text.
 * Not transactional: the table changes commit.
 *
 * @group headless
 */
class CRM_Herald_Upgrader_DigestTextTest extends TestCase implements HeadlessInterface {

  private const TABLE = 'civicrm_herald_digest';

  private const FK = 'FK_civicrm_herald_digest_message_template_id';

  /**
   * @var list<int>
   */
  private array $templates = [];

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function tearDown(): void {
    CRM_Herald_Upgrader_DigestText::move();
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_herald_digest WHERE name LIKE 'herald_test_%'");
    foreach ($this->templates as $id) {
      CRM_Core_DAO::executeQuery('DELETE FROM civicrm_msg_template WHERE id = %1', [1 => [$id, 'Positive']]);
    }
    parent::tearDown();
  }

  /**
   * The table with a template reference instead of the text.
   */
  private static function oldTable(): void {
    CRM_Core_DAO::executeQuery('ALTER TABLE ' . self::TABLE . ' DROP COLUMN msg_subject, DROP COLUMN msg_html');
    CRM_Core_DAO::executeQuery(
      'ALTER TABLE '
      . self::TABLE
      . ' ADD COLUMN message_template_id int unsigned NULL,'
      . ' ADD CONSTRAINT '
      . self::FK
      . ' FOREIGN KEY (message_template_id)'
      . ' REFERENCES civicrm_msg_template (id) ON DELETE SET NULL',
    );
  }

  private function template(string $subject, ?string $html, string $text): int {
    $id = (int) \Civi\Api4\MessageTemplate::create(checkPermissions: FALSE)
      ->setValues(['msg_title' => 'Herald test', 'msg_subject' => $subject, 'msg_html' => $html, 'msg_text' => $text])
      ->execute()
      ->single()['id'];
    $this->templates[] = $id;
    return $id;
  }

  private static function oldDigest(string $name, ?int $template): void {
    CRM_Core_DAO::executeQuery(
      'INSERT INTO ' . self::TABLE . ' (name, title, message_template_id) VALUES (%1, %1, NULLIF(%2, 0))',
      [1 => [$name, 'String'], 2 => [$template ?? 0, 'Integer']],
    );
  }

  public function testDigestsTakeOverTheTextOfTheirTemplate(): void {
    $html = $this->template('Week {$seats.booked}', '<b>{if $seats.booked > 2}full{/if}</b> &lt;3', 'unused');
    $text = $this->template('', NULL, "Booked: {\$seats.booked}\nThanks <3");
    self::oldTable();
    self::oldDigest('herald_test_html', $html);
    self::oldDigest('herald_test_text', $text);
    self::oldDigest('herald_test_none', NULL);

    self::assertSame(2, CRM_Herald_Upgrader_DigestText::move());

    $stored = static fn (string $name): array => array_intersect_key(
      Definition::load($name),
      ['msg_subject' => TRUE, 'msg_html' => TRUE],
    );
    self::assertSame(
      ['msg_subject' => 'Week {$seats.booked}', 'msg_html' => '<b>{if $seats.booked > 2}full{/if}</b> &lt;3'],
      $stored('herald_test_html'),
    );
    self::assertSame(
      ['msg_subject' => '', 'msg_html' => "Booked: {\$seats.booked}\nThanks &lt;3"],
      $stored('herald_test_text'),
    );
    self::assertSame(['msg_subject' => '', 'msg_html' => ''], $stored('herald_test_none'));
    self::assertFalse(CRM_Core_BAO_SchemaHandler::checkIfFieldExists(self::TABLE, 'message_template_id'));
    self::assertFalse(CRM_Core_BAO_SchemaHandler::checkFKExists(self::TABLE, self::FK));
    self::assertSame(
      1,
      \Civi\Api4\MessageTemplate::get(checkPermissions: FALSE)
        ->addWhere('id', '=', $html)
        ->execute()
        ->count(),
      'the template stays',
    );
  }

  public function testAPlainTextTemplateRendersAsBefore(): void {
    $text =
      "Form: {\$d.form}\n<b>not markup</b> & {if \$d.count gt 1}{\$d.count|default:'none'} gifts{/if} { spaced }"
      . "\n{\$d.empty|default:'<none>'} {if \$d.form == 'Spring <appeal>'}same{/if} {if \$d.pair eq \"a&b\"}pair{/if}";
    $vars = ['d' => ['form' => 'Spring <appeal>', 'count' => 2, 'empty' => '', 'pair' => 'a&b']];
    $renderer = new \Civi\Herald\Renderer\TemplateRenderer();

    self::assertSame(
      $renderer->renderMessage(['msg_text' => $text], $vars)['body'],
      $renderer->renderMessage(['msg_html' => CRM_Herald_Upgrader_DigestText::textAsHtml($text)], $vars)['body'],
    );
  }

  public function testATableWithTheTextIsLeftAsItIs(): void {
    self::assertSame(0, CRM_Herald_Upgrader_DigestText::move());
  }

}
