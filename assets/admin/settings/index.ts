/**
 * LM Studio Settings Models Script
 *
 * This handles the loading of available models from the LM Studio local server
 * and displays reasoning configuration dynamically in the WordPress admin panel.
 */

import './style.scss';
import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';
import { __, _n, sprintf } from '@wordpress/i18n';

// Type definitions to help TypeScript understand the data structures.
// These match the structure defined by our PHP settings class.
interface LMStudioSettingsGlobal {
	ajaxUrl?: string;
	capabilitiesAjaxUrl?: string;
	selectedModel?: string;
	selectedReasoning?: string;
}

declare global {
	interface Window {
		// WordPress exposes this global object to pass saved database settings to our JS.
		ConnectorForLMStudioSettings?: LMStudioSettingsGlobal;
	}
}

// Represents reasoning capabilities (e.g. standard vs deep thinking) configured on models.
interface ReasoningCapability {
	allowed_options: string[];
	default: string;
}

// Maps model identifiers to their specific parameters/capabilities.
interface ModelCapabilities {
	reasoning: ReasoningCapability | null;
}

// Structure representing a single model retrieved from LM Studio.
interface LMStudioModel {
	id?: string;
	name?: string;
	key?: string;
}

// Wrapper for standard WordPress API responses.
interface AjaxResponse<T> {
	success: boolean;
	data: T | string;
}

// UI design system color tokens matching standard WordPress admin styles.
const ERROR_COLOR = '#d63638';
const STATUS_COLOR = '#50575e';

// Cache to store the retrieved reasoning configurations of each model.
let modelCapabilitiesMap: Record<string, ModelCapabilities> = {};

// Safely retrieve the settings passed from WordPress PHP.
const settings = window.ConnectorForLMStudioSettings || {};

/**
 * Helper function to extract Model ID safely from response.
 *
 * Some models use the "id" field while others use "name".
 * We gracefully fall back to ensure we always have an identifier.
 *
 * @param {LMStudioModel} model Model object.
 */
const getModelId = ( model: LMStudioModel ): string => model?.id || model?.name || '';

/**
 * Get the currently selected Model ID in the select box.
 */
const getSelectedModelId = (): string => {
	const select = document.getElementById(
		'connector_for_lmstudio_settings-model',
	) as HTMLSelectElement | null;
	return select?.value || '';
};

/**
 * Renders reasoning radio buttons for the currently selected model.
 *
 * @param {string} savedReasoning Previously saved reasoning value.
 */
const renderReasoning = ( savedReasoning: string ): void => {
	const container = document.getElementById(
		'lmstudio-reasoning-container',
	);
	const fieldset = document.getElementById(
		'lmstudio-reasoning-fieldset',
	);

	// Stop if the reasoning UI containers are missing from the page layout.
	if ( ! container || ! fieldset ) {
		return;
	}

	// Remove any previously rendered radio labels (keep the <legend>).
	Array.from( fieldset.querySelectorAll( 'label' ) ).forEach( ( el ) => el.remove() );

	const modelId = getSelectedModelId();
	const capabilities = modelId ? modelCapabilitiesMap[ modelId ] : null;
	const reasoning = capabilities?.reasoning || null;

	// If this model does not support reasoning features, hide the UI section entirely.
	if (
		! reasoning ||
		! Array.isArray( reasoning.allowed_options ) ||
		reasoning.allowed_options.length === 0
	) {
		container.style.display = 'none';
		return;
	}

	// Figure out which reasoning mode should be pre-selected on load.
	const options = reasoning.allowed_options;
	const modelDefault = reasoning.default || options[ 0 ] || '';
	const effectiveValue =
		savedReasoning && options.includes( savedReasoning )
			? savedReasoning
			: modelDefault;

	const hiddenInput = document.getElementById(
		'connector_for_lmstudio_settings-reasoning',
	) as HTMLInputElement | null;

	// Sync the hidden form input so it saves correctly when the user submits the settings page.
	if ( hiddenInput ) {
		hiddenInput.value = effectiveValue;
	}

	// Dynamically build and insert radio inputs for each available reasoning option.
	options.forEach( ( option ) => {
		const label = document.createElement( 'label' );

		const radio = document.createElement( 'input' );
		radio.type = 'radio';
		radio.name = 'connector_for_lmstudio_settings[reasoning]';
		radio.id = 'lmstudio-reasoning-' + option;
		radio.value = option;
		radio.checked = option === effectiveValue;
		label.htmlFor = radio.id;

		// Update the hidden form field whenever a different radio option is checked.
		radio.addEventListener( 'change', () => {
			if ( hiddenInput ) {
				hiddenInput.value = option;
			}
		} );

		// Capitalize the first letter for a friendlier UI display label.
		const capitalised =
			option.charAt( 0 ).toUpperCase() + option.slice( 1 );
		label.appendChild( radio );
		label.appendChild( document.createTextNode( ' ' + capitalised ) );
		fieldset.appendChild( label );
	} );

	// Reveal the reasoning field section since options exist.
	container.style.display = 'block';
};

/**
 * Renders option tags inside the select element.
 *
 * @param {LMStudioModel[]} models        List of models.
 * @param {string}          selectedModel Currently selected model.
 */
const renderModels = (
	models: LMStudioModel[],
	selectedModel: string,
): void => {
	const select = document.getElementById(
		'connector_for_lmstudio_settings-model',
	) as HTMLSelectElement | null;
	const status = document.getElementById( 'lmstudio-model-status' );

	if ( ! select || ! status ) {
		return;
	}

	// Clear out any old dropdown options.
	select.innerHTML = '';

	// Add the placeholder default option allowing fallback to general client model.
	const defaultOption = document.createElement( 'option' );
	defaultOption.value = '';
	defaultOption.textContent = __( 'Use model selected by AI Client', 'connector-for-lmstudio' );
	select.appendChild( defaultOption );

	let hasSelectedModel = false;

	// Handle the edge case where no active models are available from LM Studio.
	if ( models.length === 0 ) {
		status.textContent = __(
			'No models found. Load or download a model in LM Studio and reload this page.',
			'connector-for-lmstudio',
		);
		status.style.color = ERROR_COLOR;

		// If a model was saved previously, keep displaying it so the user does not lose state.
		if ( selectedModel ) {
			const selectedOnlyOption = document.createElement( 'option' );
			selectedOnlyOption.value = selectedModel;
			/* translators: %s: Saved model identifier */
			selectedOnlyOption.textContent = sprintf( __( '%s (saved)', 'connector-for-lmstudio' ), selectedModel );
			selectedOnlyOption.selected = true;
			select.appendChild( selectedOnlyOption );
		}

		return;
	}

	// Populate the dropdown with all models retrieved from the local LM Studio server.
	models.forEach( ( model ) => {
		const modelId = getModelId( model );
		if ( ! modelId ) {
			return;
		}

		const option = document.createElement( 'option' );
		option.value = modelId;
		option.textContent = modelId;

		// Pre-select the option if it matches the user's previously saved setting.
		if ( selectedModel && modelId === selectedModel ) {
			option.selected = true;
			hasSelectedModel = true;
		}

		select.appendChild( option );
	} );

	// If the previously saved model is no longer active in LM Studio, display it with a "saved" label.
	if ( selectedModel && ! hasSelectedModel ) {
		const missingOption = document.createElement( 'option' );
		missingOption.value = selectedModel;
		/* translators: %s: Saved model identifier */
		missingOption.textContent = sprintf( __( '%s (saved)', 'connector-for-lmstudio' ), selectedModel );
		missingOption.selected = true;
		select.appendChild( missingOption );
	}

	/* translators: %d: Number of models loaded from server */
	status.textContent = sprintf( _n( '%d model loaded from server.', '%d models loaded from server.', models.length, 'connector-for-lmstudio' ), models.length );
	status.style.color = STATUS_COLOR;

	// Refresh the reasoning options dynamic field when the user switches models.
	select.addEventListener( 'change', () => {
		renderReasoning( '' );
	} );
};

// Render user-facing error state when connection or fetch fails.
const renderError = ( message: string ): void => {
	const status = document.getElementById( 'lmstudio-model-status' );
	if ( ! status ) {
		return;
	}

	status.textContent = message;
	status.style.color = ERROR_COLOR;
};

// Asynchronously load the active models from the local LM Studio server via WordPress REST API.
const loadModels = ( ajaxUrl: string, selectedModel: string ): void => {
	const status = document.getElementById( 'lmstudio-model-status' );
	if ( ! status ) {
		return;
	}

	status.textContent = __( 'Loading models…', 'connector-for-lmstudio' );

	apiFetch<AjaxResponse<LMStudioModel[]>>( {
		url: ajaxUrl,
	} )
		.then( ( payload ) => {
			if ( ! payload || ! payload.success ) {
				throw new Error(
					payload && typeof payload.data === 'string'
						? payload.data
						: __( 'Failed to load models.', 'connector-for-lmstudio' ),
				);
			}

			const models = Array.isArray( payload.data ) ? payload.data : [];
			renderModels( models, selectedModel );
		} )
		.catch( ( error: Error ) => {
			renderError(
				error?.message || __( 'Failed to load models.', 'connector-for-lmstudio' ),
			);
		} );
};

/**
 * Fetches per-model reasoning capabilities and, once available, renders the
 * reasoning radio buttons for the currently selected model.
 *
 * @param {string} capabilitiesAjaxUrl Capabilities Ajax URL.
 * @param {string} savedReasoning      Currently saved reasoning mode.
 */
const loadCapabilities = (
	capabilitiesAjaxUrl: string,
	savedReasoning: string,
): void => {
	apiFetch<AjaxResponse<Record<string, ModelCapabilities>>>( {
		url: capabilitiesAjaxUrl,
	} )
		.then( ( payload ) => {
			if (
				! payload ||
				! payload.success ||
				typeof payload.data !== 'object'
			) {
				return;
			}
			// Map and cache capabilities in local state, then draw radio controls.
			modelCapabilitiesMap = payload.data || {};
			renderReasoning( savedReasoning );
		} )
		.catch( () => {
			// Fail silently – reasoning UI simply stays hidden if endpoints fail.
		} );
};

// Main initializer method that triggers all necessary fetches.
const init = (): void => {
	if ( ! settings.ajaxUrl ) {
		return;
	}

	// Load active model list first.
	loadModels( settings.ajaxUrl, settings.selectedModel || '' );

	// Fetch dynamic model features if capabilities endpoint is defined.
	if ( settings.capabilitiesAjaxUrl ) {
		loadCapabilities(
			settings.capabilitiesAjaxUrl,
			settings.selectedReasoning || '',
		);
	}
};

// Bootstrap initialization using standard WordPress domReady callback.
domReady( init );
