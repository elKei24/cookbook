<?php

// SPDX-FileCopyrightText: 2026 Nextcloud cookbook contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Cookbook\tests\Unit\Helper;

use OCA\Cookbook\Exception\InvalidAssistantResponseException;
use OCA\Cookbook\Helper\AssistantRecipeHelper;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers OCA\Cookbook\Helper\AssistantRecipeHelper
 */
class AssistantRecipeHelperTest extends TestCase {
	/** @var AssistantRecipeHelper */
	private $dut;

	public function setUp(): void {
		parent::setUp();

		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$this->dut = new AssistantRecipeHelper($l);
	}

	public function testExtractEditableFields(): void {
		$recipe = [
			'id' => 12,
			'name' => 'Pancakes',
			'description' => 'Fluffy',
			'url' => 'https://example.com',
			'image' => 'https://example.com/image.jpg',
			'prepTime' => 'PT0H10M',
			'cookTime' => '',
			'totalTime' => 'PT0H30M',
			'recipeCategory' => 'Breakfast',
			'keywords' => 'sweet,quick',
			'recipeYield' => 4,
			'tool' => ['Pan', 42, ['nested']],
			'recipeIngredient' => ['1 cup flour', '1 egg'],
			'recipeInstructions' => ['Mix', 'Fry'],
			'nutrition' => ['calories' => '450 kcal', 'fatContent' => 12, 3 => 'invalid', 'foo' => ['bar']],
			'dateCreated' => '2024-01-01',
		];

		$expected = [
			'name' => 'Pancakes',
			'description' => 'Fluffy',
			'recipeCategory' => 'Breakfast',
			'keywords' => 'sweet,quick',
			'prepTime' => 'PT0H10M',
			'cookTime' => '',
			'totalTime' => 'PT0H30M',
			'recipeYield' => '4',
			'tool' => ['Pan', '42'],
			'recipeIngredient' => ['1 cup flour', '1 egg'],
			'recipeInstructions' => ['Mix', 'Fry'],
			'nutrition' => ['calories' => '450 kcal', 'fatContent' => '12'],
		];

		$this->assertEquals($expected, $this->dut->extractEditableFields($recipe));
	}

	public function testExtractEditableFieldsDefaults(): void {
		$result = $this->dut->extractEditableFields(['recipeYield' => null]);

		$this->assertSame('', $result['name']);
		$this->assertSame('', $result['description']);
		$this->assertNull($result['recipeYield']);
		$this->assertSame([], $result['tool']);
		$this->assertSame([], $result['recipeIngredient']);
		$this->assertSame([], $result['recipeInstructions']);
		$this->assertSame([], $result['nutrition']);
	}

	public function testBuildPrompt(): void {
		$recipe = [
			'id' => 12,
			'name' => 'Pancakes',
			'recipeIngredient' => ['1 cup flour'],
		];

		$prompt = $this->dut->buildPrompt($recipe, 'Translate the recipe to German');

		$this->assertStringContainsString('Translate the recipe to German', $prompt);
		$this->assertStringContainsString('"name": "Pancakes"', $prompt);
		$this->assertStringContainsString('"1 cup flour"', $prompt);
		$this->assertStringNotContainsString('"id"', $prompt);
	}

	/**
	 * @dataProvider dpParseResponse
	 */
	public function testParseResponse(string $output, array $expected): void {
		$this->assertEquals($expected, $this->dut->parseResponse($output));
	}

	public static function dpParseResponse(): array {
		$full = [
			'name' => 'Pfannkuchen',
			'description' => 'Fluffig',
			'recipeCategory' => 'Frühstück',
			'keywords' => 'süß,schnell',
			'prepTime' => 'PT0H10M',
			'cookTime' => '',
			'totalTime' => 'PT0H30M',
			'recipeYield' => '4',
			'tool' => ['Pfanne'],
			'recipeIngredient' => ['1 Tasse Mehl', '1 Ei'],
			'recipeInstructions' => ['Mischen', 'Braten'],
			'nutrition' => ['calories' => '450 kcal'],
		];
		$json = json_encode($full, JSON_UNESCAPED_UNICODE);

		return [
			'plain json' => [$json, $full],
			'markdown code fence' => ["Here is the recipe:\n```json\n$json\n```\nEnjoy!", $full],
			'partial answer' => ['{"name": "Foo", "unknown": "bar"}', ['name' => 'Foo']],
			'numeric values' => ['{"recipeYield": 6, "name": 12}', ['recipeYield' => '6', 'name' => '12']],
			'null yield' => ['{"recipeYield": null}', ['recipeYield' => null]],
			'invalid types dropped' => [
				'{"name": ["a"], "tool": "pan", "recipeIngredient": ["ok", {"x": 1}, 5], "nutrition": "none", "recipeYield": {"a": 1}}',
				['recipeIngredient' => ['ok', '5']],
			],
		];
	}

	/**
	 * @dataProvider dpParseResponseInvalid
	 */
	public function testParseResponseInvalid(string $output): void {
		$this->expectException(InvalidAssistantResponseException::class);
		$this->dut->parseResponse($output);
	}

	public static function dpParseResponseInvalid(): array {
		return [
			'empty' => [''],
			'prose' => ['I cannot do that.'],
			'broken json' => ['{"name": "Foo"'],
			'json list' => ['["a", "b"]'],
		];
	}
}
