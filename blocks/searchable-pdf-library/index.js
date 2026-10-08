/**
 * Gutenberg block editor script for WPCalibrate Searchable PDF Library.
 */
(function (wp) {
    const { registerBlockType } = wp.blocks;
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls } = wp.blockEditor || wp.editor;
    const { PanelBody, SelectControl, RangeControl, ToggleControl } = wp.components;
    const { __ } = wp.i18n;
    const serverSideRender = wp.serverSideRender;

    registerBlockType('wpcalibrate/searchable-pdf-library', {
        edit: function (props) {
            const { attributes, setAttributes } = props;

            return el(
                Fragment,
                {},
                el(
                    InspectorControls,
                    {},
                    el(
                        PanelBody,
                        { title: __('Library Settings', 'wpcalibrate-searchable-pdf-library'), initialOpen: true },
                        el(SelectControl, {
                            label: __('Default Layout', 'wpcalibrate-searchable-pdf-library'),
                            value: attributes.layout,
                            options: [
                                { label: __('Grid Layout', 'wpcalibrate-searchable-pdf-library'), value: 'grid' },
                                { label: __('List Layout', 'wpcalibrate-searchable-pdf-library'), value: 'list' }
                            ],
                            onChange: function (val) {
                                setAttributes({ layout: val });
                            }
                        }),
                        el(RangeControl, {
                            label: __('Documents Per Page', 'wpcalibrate-searchable-pdf-library'),
                            value: attributes.per_page,
                            min: 4,
                            max: 50,
                            onChange: function (val) {
                                setAttributes({ per_page: val });
                            }
                        }),
                        el(ToggleControl, {
                            label: __('Show Search Bar', 'wpcalibrate-searchable-pdf-library'),
                            checked: attributes.show_search,
                            onChange: function (val) {
                                setAttributes({ show_search: val });
                            }
                        }),
                        el(ToggleControl, {
                            label: __('Show Filters (Category/Tag/Sort)', 'wpcalibrate-searchable-pdf-library'),
                            checked: attributes.show_filters,
                            onChange: function (val) {
                                setAttributes({ show_filters: val });
                            }
                        })
                    )
                ),
                el('div', { className: 'wpcal-block-preview-wrapper' },
                    serverSideRender
                        ? el(serverSideRender, {
                            block: 'wpcalibrate/searchable-pdf-library',
                            attributes: attributes
                        })
                        : el('div', { className: 'wpcal-block-placeholder' },
                            el('h4', {}, __('WPCalibrate Searchable PDF Library', 'wpcalibrate-searchable-pdf-library')),
                            el('p', {}, __('Configure layout and display options in the sidebar inspector.', 'wpcalibrate-searchable-pdf-library'))
                        )
                )
            );
        },
        save: function () {
            return null; // Dynamic block rendered on server
        }
    });
})(window.wp);
