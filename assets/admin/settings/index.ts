/**
 * LM Studio Settings Models Script
 *
 * This handles the loading of available models from the LM Studio local server
 * and displays reasoning configuration dynamically in the WordPress admin panel.
 */

import './style.scss';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

// Type definitions for LM Studio Settings structures
interface LMStudioSettingsGlobal {
	ajaxUrl?: string;
	capabilitiesAjaxUrl?: string;
	selectedModel?: string;
	selectedReasoning?: string;
}

declare global {
	interface Window {
		ConnectorForLMStudioSettings?: LMStudioSettingsGlobal;
	}
}

interface ReasoningCapability {
	allowed_options: string[];
	default: string;
}

interface ModelCapabilities {
	reasoning: ReasoningCapability | null;
}

interface LMStudioModel {
	id?: string;
	name?: string;
	key?: string;
}

interface AjaxResponse<T> {
	success: boolean;
	data: T | string;
}

const ERROR_COLOR = '#d63638';
const STATUS_COLOR = '#50575e';

/** Keep track of the reasoning capabilities per model */
let modelCapabilitiesMap: Record<string, ModelCapabilities> = {};

const settings = window.ConnectorForLMStudioSettings || {};

/**
 * Helper function to extract Model ID safely from response.
 * @param {LMStudioModel} model
 */
function getModelId(model: LMStudioModel): string {
	if (model && typeof model.id === 'string' && model.id) {
		return model.id;
	}

	if (model && typeof model.name === 'string' && model.name) {
		return model.name;
	}

	return '';
}

/**
 * Get the currently selected Model ID in the select box.
 */
function getSelectedModelId(): string {
	const select = document.getElementById(
		'connector_for_lmstudio_settings-model',
	) as HTMLSelectElement | null;
	return select ? select.value : '';
}

/**
 * Renders reasoning radio buttons for the currently selected model.
 *
 * @param {string} savedReasoning Previously saved reasoning value.
 */
function renderReasoning(savedReasoning: string): void {
	const container = document.getElementById(
		'lmstudio-reasoning-container',
	);
	const fieldset = document.getElementById(
		'lmstudio-reasoning-fieldset',
	);

	if (!container || !fieldset) {
		return;
	}

	// Remove any previously rendered radio labels (keep the <legend>).
	Array.from(fieldset.querySelectorAll('label')).forEach(function (el) {
		el.remove();
	});

	const modelId = getSelectedModelId();
	const capabilities = modelId ? modelCapabilitiesMap[modelId] : null;
	const reasoning =
		capabilities && capabilities.reasoning ? capabilities.reasoning : null;

	if (
		!reasoning ||
		!Array.isArray(reasoning.allowed_options) ||
		reasoning.allowed_options.length === 0
	) {
		container.style.display = 'none';
		return;
	}

	// Determine which option to pre-select.
	const options = reasoning.allowed_options;
	const modelDefault = reasoning.default || options[0] || '';
	const effectiveValue =
		savedReasoning && options.indexOf(savedReasoning) !== -1
			? savedReasoning
			: modelDefault;

	const hiddenInput = document.getElementById(
		'connector_for_lmstudio_settings-reasoning',
	) as HTMLInputElement | null;

	// Sync the hidden input so the form always submits the current value.
	if (hiddenInput) {
		hiddenInput.value = effectiveValue;
	}

	options.forEach(function (option) {
		const label = document.createElement('label');

		const radio = document.createElement('input');
		radio.type = 'radio';
		radio.name = 'connector_for_lmstudio_settings[reasoning]';
		radio.id = 'lmstudio-reasoning-' + option;
		radio.value = option;
		radio.checked = option === effectiveValue;
		label.htmlFor = radio.id;

		radio.addEventListener('change', function () {
			if (hiddenInput) {
				hiddenInput.value = option;
			}
		});

		const capitalised =
			option.charAt(0).toUpperCase() + option.slice(1);
		label.appendChild(radio);
		label.appendChild(document.createTextNode(' ' + capitalised));
		fieldset.appendChild(label);
	});

	container.style.display = 'block';
}

/**
 * Renders option tags inside the select element.
 * @param {LMStudioModel[]} models
 * @param {string}          selectedModel
 */
function renderModels(
	models: LMStudioModel[],
	selectedModel: string,
): void {
	const select = document.getElementById(
		'connector_for_lmstudio_settings-model',
	) as HTMLSelectElement | null;
	const status = document.getElementById('lmstudio-model-status');

	if (!select || !status) {
		return;
	}

	select.innerHTML = '';

	const defaultOption = document.createElement('option');
	defaultOption.value = '';
	defaultOption.textContent = __('Use model selected by AI Client', 'connector-for-lmstudio');
	select.appendChild(defaultOption);

	let hasSelectedModel = false;

	if (models.length === 0) {
		status.textContent = __(
			'No models found. Load or download a model in LM Studio and reload this page.',
			'connector-for-lmstudio',
		);
		status.style.color = ERROR_COLOR;

		if (selectedModel) {
			const selectedOnlyOption = document.createElement('option');
			selectedOnlyOption.value = selectedModel;
			/* translators: %s: Saved model identifier */
			selectedOnlyOption.textContent = sprintf(__('%s (saved)', 'connector-for-lmstudio'), selectedModel);
			selectedOnlyOption.selected = true;
			select.appendChild(selectedOnlyOption);
		}

		return;
	}

	models.forEach(function (model) {
		const modelId = getModelId(model);
		if (!modelId) {
			return;
		}

		const option = document.createElement('option');
		option.value = modelId;
		option.textContent = modelId;

		if (selectedModel && modelId === selectedModel) {
			option.selected = true;
			hasSelectedModel = true;
		}

		select.appendChild(option);
	});

	if (selectedModel && !hasSelectedModel) {
		const missingOption = document.createElement('option');
		missingOption.value = selectedModel;
		/* translators: %s: Saved model identifier */
		missingOption.textContent = sprintf(__('%s (saved)', 'connector-for-lmstudio'), selectedModel);
		missingOption.selected = true;
		select.appendChild(missingOption);
	}

	/* translators: %d: Number of models loaded from server */
	status.textContent = sprintf(_n('%d model loaded from server.', '%d models loaded from server.', models.length, 'connector-for-lmstudio'), models.length);
	status.style.color = STATUS_COLOR;

	// Re-render reasoning whenever a different model is chosen.
	select.addEventListener('change', function () {
		renderReasoning('');
	});
}

function renderError(message: string): void {
	const status = document.getElementById('lmstudio-model-status');
	if (!status) {
		return;
	}

	status.textContent = message;
	status.style.color = ERROR_COLOR;
}

function loadModels(ajaxUrl: string, selectedModel: string): void {
	const status = document.getElementById('lmstudio-model-status');
	if (!status) {
		return;
	}

	status.textContent = __('Loading models…', 'connector-for-lmstudio');

	apiFetch<AjaxResponse<LMStudioModel[]>>({
		url: ajaxUrl,
	})
		.then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error(
					payload && typeof payload.data === 'string'
						? payload.data
						: __('Failed to load models.', 'connector-for-lmstudio'),
				);
			}

			const models = Array.isArray(payload.data) ? payload.data : [];
			renderModels(models, selectedModel);
		})
		.catch(function (error: Error) {
			renderError(
				error && error.message ? error.message : __('Failed to load models.', 'connector-for-lmstudio'),
			);
		});
}

/**
 * Fetches per-model reasoning capabilities and, once available, renders the
 * reasoning radio buttons for the currently selected model.
 * @param {string} capabilitiesAjaxUrl
 * @param {string} savedReasoning
 */
function loadCapabilities(
	capabilitiesAjaxUrl: string,
	savedReasoning: string,
): void {
	apiFetch<AjaxResponse<Record<string, ModelCapabilities>>>({
		url: capabilitiesAjaxUrl,
	})
		.then(function (payload) {
			if (
				!payload ||
				!payload.success ||
				typeof payload.data !== 'object'
			) {
				return;
			}
			modelCapabilitiesMap = payload.data || {};
			renderReasoning(savedReasoning);
		})
		.catch(function () {
			// Fail silently – reasoning UI simply stays hidden.
		});
}

function init(): void {
	if (
		!settings.ajaxUrl
	) {
		return;
	}

	loadModels(settings.ajaxUrl, settings.selectedModel || '');

	if (settings.capabilitiesAjaxUrl) {
		loadCapabilities(
			settings.capabilitiesAjaxUrl,
			settings.selectedReasoning || '',
		);
	}
}

// Bootstrap initialization when DOM is ready
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init);
} else {
	init();
}
