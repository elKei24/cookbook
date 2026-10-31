<?php

// SPDX-FileCopyrightText: 2026 Nextcloud cookbook contributors
//
// SPDX-License-Identifier: AGPL-3.0-only OR AGPL-3.0-or-later

namespace OCA\Cookbook\Helper;

use OCA\Cookbook\Exception\InvalidAssistantResponseException;
use OCP\IL10N;

/**
 * Helper to exchange recipes with the Nextcloud Assistant.
 *
 * It creates the prompt that is sent to the text processing task and parses the answer of the
 * assistant back into the recipe fields of the editor.
 */
class AssistantRecipeHelper {
	/**
	 * The recipe fields holding a single string value that the assistant may modify.
	 */
	private const STRING_FIELDS = [
		'name',
		'description',
		'recipeCategory',
		'keywords',
		'prepTime',
		'cookTime',
		'totalTime',
	];

	/**
	 * The recipe fields holding a list of strings that the assistant may modify.
	 */
	private const LIST_FIELDS = [
		'tool',
		'recipeIngredient',
		'recipeInstructions',
	];

	/** @var IL10N */
	private $l;

	public function __construct(IL10N $l) {
		$this->l = $l;
	}

	/**
	 * Reduce a recipe to the fields that are sent to the assistant.
	 *
	 * Technical fields like the id or the image are never handed to the assistant.
	 *
	 * @param array $recipe The recipe as present in the editor
	 * @return array The fields of the recipe that may be changed by the assistant
	 */
	public function extractEditableFields(array $recipe): array {
		$result = [];

		foreach (self::STRING_FIELDS as $field) {
			$result[$field] = isset($recipe[$field]) && is_scalar($recipe[$field]) ? (string)$recipe[$field] : '';
		}

		$yield = $recipe['recipeYield'] ?? null;
		$result['recipeYield'] = is_scalar($yield) ? (string)$yield : null;

		foreach (self::LIST_FIELDS as $field) {
			$result[$field] = $this->sanitizeStringList($recipe[$field] ?? []);
		}

		$result['nutrition'] = $this->sanitizeNutrition($recipe['nutrition'] ?? []);

		return $result;
	}

	/**
	 * Build the prompt that is sent to the assistant
	 *
	 * @param array $recipe The recipe as present in the editor
	 * @param string $instruction The instruction of the user, e.g., "Translate the recipe to English"
	 * @return string The complete prompt for the text processing task
	 */
	public function buildPrompt(array $recipe, string $instruction): string {
		$json = json_encode(
			$this->extractEditableFields($recipe),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$lines = [
			'You are a helpful assistant that edits cooking recipes.',
			'Below you find a recipe as a JSON object and an instruction from the user.',
			'Apply the instruction to the recipe and return the complete updated recipe.',
			'',
			'Rules:',
			'- Answer with a single JSON object only. Do not add any explanation, markdown or code fences.',
			'- Keep exactly the same keys and the same data types as in the given JSON object.',
			'- Only change the content that is necessary to follow the instruction. Keep all other values untouched.',
			'- "keywords" is a single string of comma-separated keywords.',
			'- "recipeYield" is the number of servings as a string or null if unknown.',
			'- "prepTime", "cookTime" and "totalTime" are ISO 8601 durations (e.g. "PT1H30M") or empty strings.',
			'- "tool", "recipeIngredient" and "recipeInstructions" are lists of strings, one entry per item or step.',
			'- "nutrition" is an object mapping nutrition keys to strings.',
			'',
			'Instruction:',
			$instruction,
			'',
			'Recipe:',
			$json,
		];

		return implode("\n", $lines);
	}

	/**
	 * Parse the answer of the assistant into the recipe fields of the editor
	 *
	 * Only known fields with valid types are returned. All other data of the answer is dropped.
	 *
	 * @param string $output The raw text answer of the assistant
	 * @return array The updated recipe fields
	 * @throws InvalidAssistantResponseException if the answer does not contain a JSON object
	 */
	public function parseResponse(string $output): array {
		$data = json_decode($this->extractJson($output), true);

		if (!is_array($data)) {
			throw new InvalidAssistantResponseException(
				$this->l->t('The answer of the assistant could not be interpreted as a recipe.')
			);
		}

		$result = [];

		foreach (self::STRING_FIELDS as $field) {
			if (array_key_exists($field, $data) && is_scalar($data[$field])) {
				$result[$field] = (string)$data[$field];
			}
		}

		if (array_key_exists('recipeYield', $data)) {
			if ($data['recipeYield'] === null) {
				$result['recipeYield'] = null;
			} elseif (is_scalar($data['recipeYield'])) {
				$result['recipeYield'] = (string)$data['recipeYield'];
			}
		}

		foreach (self::LIST_FIELDS as $field) {
			if (array_key_exists($field, $data) && is_array($data[$field])) {
				$result[$field] = $this->sanitizeStringList($data[$field]);
			}
		}

		if (array_key_exists('nutrition', $data) && is_array($data['nutrition'])) {
			$result['nutrition'] = $this->sanitizeNutrition($data['nutrition']);
		}

		return $result;
	}

	/**
	 * Extract the JSON object from the answer of the assistant
	 *
	 * Language models tend to wrap the answer in markdown code fences or to add some prose around it.
	 * This method cuts out the outermost JSON object.
	 *
	 * @param string $output The raw answer
	 * @return string The part of the answer that most probably is the JSON object
	 */
	private function extractJson(string $output): string {
		$start = strpos($output, '{');
		$end = strrpos($output, '}');

		if ($start === false || $end === false || $end < $start) {
			return '';
		}

		return substr($output, $start, $end - $start + 1);
	}

	/**
	 * @param mixed $list
	 * @return string[]
	 */
	private function sanitizeStringList($list): array {
		if (!is_array($list)) {
			return [];
		}

		$result = [];
		foreach ($list as $entry) {
			if (is_scalar($entry)) {
				$result[] = (string)$entry;
			}
		}

		return $result;
	}

	/**
	 * @param mixed $nutrition
	 * @return array<string, string>
	 */
	private function sanitizeNutrition($nutrition): array {
		if (!is_array($nutrition)) {
			return [];
		}

		$result = [];
		foreach ($nutrition as $key => $value) {
			if (is_string($key) && is_scalar($value)) {
				$result[$key] = (string)$value;
			}
		}

		return $result;
	}
}
