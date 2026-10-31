// SPDX-FileCopyrightText: 2026 Nextcloud cookbook contributors
//
// SPDX-License-Identifier: AGPL-3.0-only OR AGPL-3.0-or-later

import { ref } from 'vue';

import api from 'cookbook/js/api-interface';

/**
 * Whether the Nextcloud Assistant can be used to process recipes.
 * `null` means that the availability has not been determined yet.
 */
const isAvailable = ref<boolean | null>(null);
let pendingCheck: Promise<boolean> | null = null;

/**
 * Access to the Nextcloud Assistant integration.
 *
 * The availability of the assistant is only queried once per page load and shared
 * between all components using this composable.
 */
export function useAssistant() {
	function checkAvailability(): Promise<boolean> {
		if (isAvailable.value !== null) {
			return Promise.resolve(isAvailable.value);
		}
		if (pendingCheck === null) {
			pendingCheck = api.assistant
				.status()
				.then((response) => {
					isAvailable.value = !!response.data?.available;
					return isAvailable.value;
				})
				.catch(() => {
					// If the status cannot be determined, keep the assistant hidden
					isAvailable.value = false;
					return false;
				})
				.finally(() => {
					pendingCheck = null;
				});
		}
		return pendingCheck;
	}

	return {
		isAvailable,
		checkAvailability,
	};
}
