{{--
    `toggle` is the name the settings and preference screens use; `switch` is the
    name the component library uses. One implementation, two doors — renaming
    either side would silently break the other.
--}}
<x-ui.switch {{ $attributes }}>
    {{ $slot }}
</x-ui.switch>
