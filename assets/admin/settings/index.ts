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
	selectedModel?: string;
	selectedReasoning?: string;
	svgs?: {
		vision?: string;
		tools?: string;
		reasoning?: string;
		error?: string;
		success?: string;
	};
}

declare global {
	interface Window {
		// WordPress exposes this global object to pass saved database settings to our JS.
		AIProviderForLMStudioSettings?: LMStudioSettingsGlobal;
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
	vision?: boolean;
	trained_for_tool_use?: boolean;
}

// Structure representing a single model retrieved from LM Studio.
interface LMStudioModel {
	id?: string;
	name?: string;
	key?: string;
}

// Cache to store the retrieved reasoning configurations of each model.
let modelCapabilitiesMap: Record<string, ModelCapabilities> = {};

// Safely retrieve the settings passed from WordPress PHP.
const settings = window.AIProviderForLMStudioSettings || {};

// SVG URLs passed from PHP via wp_localize_script.
const SVGS = settings.svgs || {};

/**
 * Creates an <img> element pointing to the SVG asset file.
 *
 * @param {string} name The SVG identifier.
 */
const getSVGElement = ( name: string ): HTMLImageElement | null => {
	const url = SVGS[ name as keyof typeof SVGS ];
	if ( ! url ) {
		return null;
	}
	const img = document.createElement( 'img' );
	img.src = url;
	img.alt = '';
	img.width = 24;
	img.height = 24;
	img.setAttribute( 'aria-hidden', 'true' );
	return img;
};

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
		'ai_provider_for_lm_studio_settings-model',
	) as HTMLSelectElement | null;
	return select?.value || '';
};

/**
 * Renders beautiful capability indicator badges (Vision, Tool Calling, Reasoning)
 * next to the selected model dropdown.
 */
const renderCapabilitiesBadges = (): void => {
	const select = document.getElementById(
		'ai_provider_for_lm_studio_settings-model',
	) as HTMLSelectElement | null;
	const container = document.getElementById( 'lmstudio-models-container' );

	if ( ! select || ! container ) {
		return;
	}

	// Find or create badges container
	let badgesContainer = document.getElementById( 'lmstudio-capabilities-badges-container' );
	if ( ! badgesContainer ) {
		badgesContainer = document.createElement( 'div' );
		badgesContainer.id = 'lmstudio-capabilities-badges-container';
		badgesContainer.className = 'lmstudio-capabilities-badges-container';

		// Insert directly inside models container
		container.appendChild( badgesContainer );
	}

	// Clear previous badges
	badgesContainer.innerHTML = '';

	const modelId = select.value;
	if ( ! modelId ) {
		badgesContainer.style.display = 'none';
		return;
	}

	const capabilities = modelCapabilitiesMap[ modelId ];
	if ( ! capabilities ) {
		badgesContainer.style.display = 'none';
		return;
	}

	// Create vision badge if true
	if ( capabilities.vision ) {
		const visionBadge = document.createElement( 'span' );
		visionBadge.className = 'lmstudio-cap-badge lmstudio-cap-vision';
		const svg = getSVGElement( 'vision' );
		if ( svg ) {
			visionBadge.appendChild( svg );
		}
		visionBadge.appendChild( document.createTextNode( __( 'Vision', 'ai-provider-for-lm-studio' ) ) );
		badgesContainer.appendChild( visionBadge );
	}

	// Create tool use badge if true
	if ( capabilities.trained_for_tool_use ) {
		const toolBadge = document.createElement( 'span' );
		toolBadge.className = 'lmstudio-cap-badge lmstudio-cap-tools';
		const svg = getSVGElement( 'tools' );
		if ( svg ) {
			toolBadge.appendChild( svg );
		}
		toolBadge.appendChild( document.createTextNode( __( 'Tool Calling', 'ai-provider-for-lm-studio' ) ) );
		badgesContainer.appendChild( toolBadge );
	}

	// Create reasoning support badge if reasoning capability exists
	const reasoning = capabilities.reasoning || null;
	if (
		reasoning &&
		Array.isArray( reasoning.allowed_options ) &&
		reasoning.allowed_options.length > 0
	) {
		const reasoningBadge = document.createElement( 'span' );
		reasoningBadge.className = 'lmstudio-cap-badge lmstudio-cap-reasoning';
		const svg = getSVGElement( 'reasoning' );
		if ( svg ) {
			reasoningBadge.appendChild( svg );
		}
		reasoningBadge.appendChild( document.createTextNode( __( 'Reasoning', 'ai-provider-for-lm-studio' ) ) );
		badgesContainer.appendChild( reasoningBadge );
	}

	// Show container if it has badges
	if ( badgesContainer.children.length > 0 ) {
		badgesContainer.style.display = 'flex';
	} else {
		badgesContainer.style.display = 'none';
	}
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
		container.classList.remove( 'lmstudio-visible' );
		container.style.display = 'none';
		const tr = container.closest( 'tr' );
		if ( tr ) {
			tr.style.display = 'none';
		}
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
		'ai_provider_for_lm_studio_settings-reasoning',
	) as HTMLInputElement | null;

	// Sync the hidden form input so it saves correctly when the user submits the settings page.
	if ( hiddenInput ) {
		hiddenInput.value = effectiveValue;
	}

	// Dynamically build and insert radio inputs for each available reasoning option.
	options.forEach( ( option ) => {
		const label = document.createElement( 'label' );
		label.className = 'lmstudio-reasoning-card';
		if ( option === effectiveValue ) {
			label.classList.add( 'lmstudio-checked' );
		}

		const radio = document.createElement( 'input' );
		radio.type = 'radio';
		radio.name = 'ai_provider_for_lm_studio_settings[reasoning]';
		radio.id = 'lmstudio-reasoning-' + option;
		radio.value = option;
		radio.checked = option === effectiveValue;
		label.htmlFor = radio.id;

		// Update the hidden form field whenever a different radio option is checked.
		radio.addEventListener( 'change', () => {
			if ( hiddenInput ) {
				hiddenInput.value = option;
			}
			Array.from( fieldset.querySelectorAll( 'label' ) ).forEach( ( el ) => el.classList.remove( 'lmstudio-checked' ) );
			label.classList.add( 'lmstudio-checked' );
		} );

		// Capitalize the first letter for a friendlier UI display label.
		const capitalised =
			option.charAt( 0 ).toUpperCase() + option.slice( 1 );

		// Assemble premium custom components inside the radio card
		label.appendChild( radio );

		const headerDiv = document.createElement( 'div' );
		headerDiv.className = 'lmstudio-card-header';

		const titleSpan = document.createElement( 'span' );
		titleSpan.className = 'lmstudio-card-title';
		titleSpan.textContent = capitalised;

		const indicatorDiv = document.createElement( 'div' );
		indicatorDiv.className = 'lmstudio-radio-indicator';

		headerDiv.appendChild( titleSpan );
		headerDiv.appendChild( indicatorDiv );
		label.appendChild( headerDiv );

		const descDiv = document.createElement( 'div' );
		descDiv.className = 'lmstudio-card-desc';
		if ( option === 'on' ) {
			descDiv.textContent = __( 'Enable full deep thinking reasoning capability for high-quality problem solving.', 'ai-provider-for-lm-studio' );
		} else if ( option === 'off' ) {
			descDiv.textContent = __( 'Disable reasoning mode for standard fast responses without extended thinking cycles.', 'ai-provider-for-lm-studio' );
		} else {
			/* translators: %s: reasoning option mode label */
			descDiv.textContent = sprintf( __( 'Activate "%s" reasoning capability mode.', 'ai-provider-for-lm-studio' ), capitalised );
		}
		label.appendChild( descDiv );

		fieldset.appendChild( label );
	} );

	// Reveal the reasoning field section since options exist.
	container.style.display = 'block';
	requestAnimationFrame( () => {
		container.classList.add( 'lmstudio-visible' );
	} );
	const tr = container.closest( 'tr' );
	if ( tr ) {
		tr.style.display = '';
	}
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
		'ai_provider_for_lm_studio_settings-model',
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
	defaultOption.textContent = __( 'Use model selected by AI Client', 'ai-provider-for-lm-studio' );
	select.appendChild( defaultOption );

	let hasSelectedModel = false;

	// Handle the edge case where no active models are available from LM Studio.
	if ( models.length === 0 ) {
		const noModelsBadge = document.createElement( 'span' );
		noModelsBadge.className = 'lmstudio-loader-badge lmstudio-error-badge';
		const svg = getSVGElement( 'error' );
		if ( svg ) {
			noModelsBadge.appendChild( svg );
		}
		noModelsBadge.appendChild( document.createTextNode( __( 'No models found', 'ai-provider-for-lm-studio' ) ) );
		status.innerHTML = '';
		status.appendChild( noModelsBadge );

		// If a model was saved previously, keep displaying it so the user does not lose state.
		if ( selectedModel ) {
			const selectedOnlyOption = document.createElement( 'option' );
			selectedOnlyOption.value = selectedModel;
			/* translators: %s: Saved model identifier */
			selectedOnlyOption.textContent = sprintf( __( '%s (saved)', 'ai-provider-for-lm-studio' ), selectedModel );
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
		missingOption.textContent = sprintf( __( '%s (saved)', 'ai-provider-for-lm-studio' ), selectedModel );
		missingOption.selected = true;
		select.appendChild( missingOption );
	}

	// Render the model loaded count beautifully
	const countText = sprintf(
		/* translators: %d: number of models loaded */
		_n( '%d model loaded', '%d models loaded', models.length, 'ai-provider-for-lm-studio' ),
		models.length,
	);
	const successBadge = document.createElement( 'span' );
	successBadge.className = 'lmstudio-loader-badge lmstudio-success-badge';
	const svg = getSVGElement( 'success' );
	if ( svg ) {
		successBadge.appendChild( svg );
	}
	successBadge.appendChild( document.createTextNode( countText ) );
	status.innerHTML = '';
	status.appendChild( successBadge );

	// Re-enable input selector once load completes
	select.disabled = false;

	// Refresh the reasoning options dynamic field when the user switches models.
	select.addEventListener( 'change', () => {
		renderReasoning( '' );
		renderCapabilitiesBadges();
	} );
};

// Render user-facing error state when connection or fetch fails.
const renderError = ( message: string ): void => {
	const status = document.getElementById( 'lmstudio-model-status' );
	if ( ! status ) {
		return;
	}

	const errorBadge = document.createElement( 'span' );
	errorBadge.className = 'lmstudio-loader-badge lmstudio-error-badge';
	errorBadge.title = message;
	const svg = getSVGElement( 'error' );
	if ( svg ) {
		errorBadge.appendChild( svg );
	}
	errorBadge.appendChild( document.createTextNode( __( 'Connection failed', 'ai-provider-for-lm-studio' ) ) );
	status.innerHTML = '';
	status.appendChild( errorBadge );
};

// Asynchronously load the active models from the local LM Studio server via WordPress REST API.
const loadModels = ( selectedModel: string ): void => {
	const status = document.getElementById( 'lmstudio-model-status' );
	const select = document.getElementById(
		'ai_provider_for_lm_studio_settings-model',
	) as HTMLSelectElement | null;

	if ( ! status ) {
		return;
	}

	// Disable dropdown while fetching and render spinner badge
	if ( select ) {
		select.disabled = true;
	}
	const loadingBadge = document.createElement( 'span' );
	loadingBadge.className = 'lmstudio-loader-badge';
	const spinner = document.createElement( 'div' );
	spinner.className = 'lmstudio-spinner';
	loadingBadge.appendChild( spinner );
	loadingBadge.appendChild( document.createTextNode( __( 'Fetching models…', 'ai-provider-for-lm-studio' ) ) );
	status.innerHTML = '';
	status.appendChild( loadingBadge );

	apiFetch<LMStudioModel[]>( {
		path: '/ai-provider-for-lm-studio/v1/models',
	} )
		.then( ( models ) => {
			const modelsList = Array.isArray( models ) ? models : [];
			renderModels( modelsList, selectedModel );
		} )
		.catch( ( error: unknown ) => {
			if ( select ) {
				select.disabled = false;
			}
			const err = error as { message?: string };
			renderError(
				err?.message || __( 'Failed to load models.', 'ai-provider-for-lm-studio' ),
			);
		} );
};

/**
 * Fetches per-model reasoning capabilities and, once available, renders the
 * reasoning radio buttons for the currently selected model.
 *
 * @param {string} savedReasoning Currently saved reasoning mode.
 */
const loadCapabilities = ( savedReasoning: string ): void => {
	apiFetch<Record<string, ModelCapabilities>>( {
		path: '/ai-provider-for-lm-studio/v1/capabilities',
	} )
		.then( ( capabilities ) => {
			if ( ! capabilities || typeof capabilities !== 'object' ) {
				return;
			}
			// Map and cache capabilities in local state, then draw radio controls.
			modelCapabilitiesMap = capabilities || {};
			renderReasoning( savedReasoning );
			renderCapabilitiesBadges();
		} )
		.catch( () => {
			// Fail silently – reasoning UI simply stays hidden if endpoints fail.
		} );
};

// Main initializer method that triggers all necessary fetches.
const init = (): void => {
	// Load active model list first.
	loadModels( settings.selectedModel || '' );

	// Fetch dynamic model features if capabilities endpoint is defined.
	loadCapabilities( settings.selectedReasoning || '' );
};

// Bootstrap initialization using standard WordPress domReady callback.
domReady( init );
