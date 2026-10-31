(function () {
    const OSM = {
        url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    };

    const SATELLITE = {
        url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
        attribution: 'Tiles &copy; Esri',
    };

    const COLORS = {
        idle: '#6366f1',
        selected: '#83211b',
        draft: '#15803d',
        inactive: '#9ca3af',
    };

    function toLatLngs(points) {
        if (!Array.isArray(points)) return [];
        return points
            .map(function (point) {
                const lat = parseFloat(point.lat ?? point.latitude);
                const lng = parseFloat(point.lng ?? point.longitude);
                if (Number.isNaN(lat) || Number.isNaN(lng)) return null;
                return [lat, lng];
            })
            .filter(Boolean);
    }

    function fromLatLngs(latlngs) {
        return latlngs.map(function (ll) {
            return { lat: Number(ll.lat.toFixed(7)), lng: Number(ll.lng.toFixed(7)) };
        });
    }

    window.GeofenceEditor = {
        map: null,
        overlay: null,
        draftLayer: null,
        vertexMarkers: [],
        locations: [],
        selectedId: null,
        drawType: 'circle',
        drawing: false,
        showingSelectedOnly: false,
        wire: null,
        defaultCenter: { lat: 10.77, lng: 77.08 },
        streetLayer: null,
        satelliteLayer: null,
        baseLayer: 'street',
        pinMarker: null,
        searchTimer: null,
        searchUrl: null,

        boot: function (wire, center) {
            this.wire = wire;
            if (center && center.lat && center.lng) {
                this.defaultCenter = center;
            }
            this.initMap();
            this.bindLivewire();
            this.bindToolbar();
        },

        initMap: function () {
            if (this.map || typeof L === 'undefined') {
                return;
            }
            const el = document.getElementById('geofence-map');
            if (!el) return;

            this.map = L.map(el, { zoomControl: true }).setView(
                [this.defaultCenter.lat, this.defaultCenter.lng],
                16
            );
            this.streetLayer = L.tileLayer(OSM.url, { attribution: OSM.attribution, maxZoom: 19 });
            this.satelliteLayer = L.tileLayer(SATELLITE.url, {
                attribution: SATELLITE.attribution,
                maxZoom: 19,
            });
            this.streetLayer.addTo(this.map);
            this.overlay = L.featureGroup().addTo(this.map);

            this.map.on('click', (event) => this.onMapClick(event));
            setTimeout(() => this.map.invalidateSize(), 200);
        },

        bindLivewire: function () {
            if (typeof Livewire === 'undefined' || this._bound) return;
            this._bound = true;
            Livewire.on('geofences-synced', (payload) => {
                const data = this.unwrapPayload(payload);
                this.sync(data.locations || [], data.selectedId);
            });
            Livewire.on('geofence-draw-start', (payload) => {
                const data = this.unwrapPayload(payload);
                this.startDraw(data.boundaryType || 'circle');
            });
            Livewire.on('geofence-edit', (payload) => {
                const data = this.unwrapPayload(payload);
                this.loadEdit(data.location || data);
            });
            Livewire.on('geofence-focus', (payload) => {
                const data = this.unwrapPayload(payload);
                this.focusLocation(this.extractLocation(data) || data.location || data);
            });
            Livewire.on('geofence-editor-closed', () => this.clearDraft());
        },

        unwrapPayload: function (payload) {
            if (!payload || typeof payload !== 'object') return {};
            if (payload.location || payload.locations || payload.boundaryType) return payload;
            if (Array.isArray(payload) && payload[0]) return payload[0];
            return payload;
        },

        extractLocation: function (payload) {
            if (!payload || typeof payload !== 'object') return null;
            if (payload.id != null && (payload.boundary_type || payload.latitude != null || payload.polygon_coordinates)) {
                return payload;
            }
            if (payload.location && typeof payload.location === 'object' && payload.location.id != null) {
                return payload.location;
            }
            return null;
        },

        hasGeometry: function (location) {
            if (!location) return false;
            if (location.boundary_type === 'polygon') {
                return toLatLngs(location.polygon_coordinates).length >= 3;
            }
            const lat = parseFloat(location.latitude);
            const lng = parseFloat(location.longitude);
            return Number.isFinite(lat) && Number.isFinite(lng);
        },

        mergeLocation: function (incoming) {
            if (!incoming || incoming.id == null) return null;
            const id = Number(incoming.id);
            const index = this.locations.findIndex(function (item) {
                return Number(item.id) === id;
            });
            const existing = index >= 0 ? this.locations[index] : {};
            const merged = Object.assign({}, existing, incoming);
            if (!this.hasGeometry(incoming) && this.hasGeometry(existing)) {
                merged.boundary_type = existing.boundary_type;
                merged.latitude = existing.latitude;
                merged.longitude = existing.longitude;
                merged.radius = existing.radius;
                merged.polygon_coordinates = existing.polygon_coordinates;
            }
            if (index >= 0) {
                this.locations[index] = merged;
            } else {
                this.locations.push(merged);
            }
            return merged;
        },

        sync: function (locations, selectedId) {
            const firstSync = !this._synced;
            this._synced = true;
            this.locations = Array.isArray(locations) ? locations : [];
            if (selectedId !== undefined && selectedId !== null) {
                this.selectedId = selectedId;
            }
            if (firstSync) {
                this.showingSelectedOnly = false;
            }
            this.redrawOverlays({ fitAll: firstSync && !this.drawing });
        },

        focusById: function (id) {
            const location = this.locations.find(function (item) {
                return Number(item.id) === Number(id);
            });
            if (!location) {
                this.call('focus', Number(id));
                return;
            }
            this.focusLocation(location);
        },

        focusLocation: function (location) {
            if (!location || !this.map) return;
            const incoming = this.extractLocation(location) || location;
            if (incoming.id == null) return;

            this.drawing = false;
            this.clearDraftLayer();
            this.clearPin();

            const merged = this.mergeLocation(incoming);
            if (!merged || !this.hasGeometry(merged)) {
                this.showMapError('This boundary has no map geometry.');
                this.selectedId = null;
                this.showingSelectedOnly = true;
                if (this.overlay) {
                    this.overlay.clearLayers();
                }
                return;
            }

            this.clearMapError();
            this.selectedId = merged.id;
            this.showingSelectedOnly = true;
            this.redrawOverlays({ fitAll: false, selectedOnly: true });
            this.fitOverlayToSelection(merged);
        },

        overlayLayerForId: function (id) {
            if (!this.overlay) return null;
            let found = null;
            this.overlay.eachLayer(function (layer) {
                if (found) return;
                if (Number(layer._geofenceId) === Number(id)) {
                    found = layer;
                }
            });
            return found;
        },

        fitOverlayToSelection: function (location) {
            if (!location || !this.map) return;
            this.map.invalidateSize();
            const layer = this.overlayLayerForId(location.id);
            const fitOptions = { padding: [48, 48], maxZoom: 18 };
            if (layer && typeof layer.getBounds === 'function') {
                const bounds = layer.getBounds();
                if (bounds && bounds.isValid()) {
                    this.map.fitBounds(bounds, fitOptions);
                    return;
                }
            }
            this.fitLocation(location);
        },

        fitLocation: function (location) {
            if (!location || !this.map) return;
            const fitOptions = { padding: [48, 48], maxZoom: 18 };
            if (location.boundary_type === 'polygon') {
                const latlngs = toLatLngs(location.polygon_coordinates);
                if (latlngs.length < 3) {
                    this.showMapError('This polygon has no map geometry.');
                    return;
                }
                this.map.fitBounds(L.latLngBounds(latlngs), fitOptions);
                return;
            }
            const lat = parseFloat(location.latitude);
            const lng = parseFloat(location.longitude);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                this.showMapError('This boundary has no map geometry.');
                return;
            }
            this.map.setView([lat, lng], 17);
        },

        startDraw: function (boundaryType) {
            this.drawing = true;
            this.showingSelectedOnly = false;
            this.drawType = boundaryType === 'polygon' ? 'polygon' : 'circle';
            this.clearPin();
            this.clearDraftLayer();
            this.selectedId = null;
            this.redrawOverlays();
        },

        loadEdit: function (location) {
            if (!location) return;
            this.drawing = true;
            this.showingSelectedOnly = false;
            this.selectedId = location.id;
            this.drawType = location.boundary_type === 'polygon' ? 'polygon' : 'circle';
            this.clearPin();
            this.redrawOverlays();
            this.paintDraftFromLocation(location);
            this.fitDraft();
        },

        clearDraft: function () {
            this.drawing = false;
            this.showingSelectedOnly = false;
            this.selectedId = null;
            this.clearDraftLayer();
            this.redrawOverlays();
        },

        onMapClick: function (event) {
            if (!this.drawing) return;
            this.clearPin();
            if (this.drawType === 'circle') {
                this.setCircle(event.latlng, this.currentRadius());
                return;
            }
            this.addPolygonVertex(event.latlng);
        },

        currentRadius: function () {
            const input = document.getElementById('geofence-radius');
            const value = input ? parseInt(input.value, 10) : NaN;
            return Number.isFinite(value) && value >= 10 ? value : 80;
        },

        setCircle: function (latlng, radius) {
            this.clearDraftLayer();
            this.draftLayer = L.circle(latlng, {
                radius: radius,
                color: COLORS.draft,
                fillColor: COLORS.draft,
                fillOpacity: 0.18,
                weight: 2,
            }).addTo(this.map);
            this.pushGeometry({
                boundary_type: 'circle',
                latitude: Number(latlng.lat.toFixed(7)),
                longitude: Number(latlng.lng.toFixed(7)),
                radius: Math.round(radius),
                polygon_coordinates: [],
            });
        },

        resizeCircle: function (radius) {
            if (!this.draftLayer || typeof this.draftLayer.setRadius !== 'function') return;
            this.draftLayer.setRadius(radius);
            const latlng = this.draftLayer.getLatLng();
            this.pushGeometry({
                boundary_type: 'circle',
                latitude: Number(latlng.lat.toFixed(7)),
                longitude: Number(latlng.lng.toFixed(7)),
                radius: Math.round(radius),
                polygon_coordinates: [],
            });
        },

        addPolygonVertex: function (latlng) {
            if (!this.draftLayer) {
                this.draftLayer = L.polygon([latlng], {
                    color: COLORS.draft,
                    fillColor: COLORS.draft,
                    fillOpacity: 0.18,
                    weight: 2,
                }).addTo(this.map);
            } else {
                const latlngs = this.draftLayer.getLatLngs()[0] || [];
                if (latlngs.length >= 32) return;
                latlngs.push(latlng);
                this.draftLayer.setLatLngs(latlngs);
            }
            this.refreshVertices();
            this.pushPolygon();
        },

        removeLastVertex: function () {
            if (!this.draftLayer || this.drawType !== 'polygon') return;
            const latlngs = (this.draftLayer.getLatLngs()[0] || []).slice();
            latlngs.pop();
            if (latlngs.length === 0) {
                this.clearDraftLayer();
                this.pushGeometry({
                    boundary_type: 'polygon',
                    polygon_coordinates: [],
                    latitude: null,
                    longitude: null,
                    radius: null,
                });
                return;
            }
            this.draftLayer.setLatLngs(latlngs);
            this.refreshVertices();
            this.pushPolygon();
        },

        refreshVertices: function () {
            this.vertexMarkers.forEach((marker) => this.map.removeLayer(marker));
            this.vertexMarkers = [];
            if (!this.draftLayer || this.drawType !== 'polygon') return;
            const latlngs = this.draftLayer.getLatLngs()[0] || [];
            latlngs.forEach((ll, index) => {
                const marker = L.circleMarker(ll, {
                    radius: 6,
                    color: '#fff',
                    fillColor: COLORS.draft,
                    fillOpacity: 1,
                    weight: 2,
                    draggable: false,
                }).addTo(this.map);
                marker.on('click', (event) => {
                    L.DomEvent.stopPropagation(event);
                    this.removeVertexAt(index);
                });
                this.vertexMarkers.push(marker);
            });
        },

        removeVertexAt: function (index) {
            if (!this.draftLayer) return;
            const latlngs = (this.draftLayer.getLatLngs()[0] || []).slice();
            latlngs.splice(index, 1);
            if (latlngs.length === 0) {
                this.clearDraftLayer();
                this.pushPolygon();
                return;
            }
            this.draftLayer.setLatLngs(latlngs);
            this.refreshVertices();
            this.pushPolygon();
        },

        pushPolygon: function () {
            if (!this.draftLayer) {
                this.pushGeometry({
                    boundary_type: 'polygon',
                    polygon_coordinates: [],
                });
                return;
            }
            const latlngs = this.draftLayer.getLatLngs()[0] || [];
            this.pushGeometry({
                boundary_type: 'polygon',
                polygon_coordinates: fromLatLngs(latlngs),
            });
        },

        call: function (method, arg) {
            if (!this.wire) return;
            if (this.wire.$wire && typeof this.wire.$wire[method] === 'function') {
                return this.wire.$wire[method](arg);
            }
            if (typeof this.wire.call === 'function') {
                return this.wire.call(method, arg);
            }
            if (typeof this.wire[method] === 'function') {
                return this.wire[method](arg);
            }
        },

        pushGeometry: function (geometry) {
            this.call('applyGeometry', geometry);
        },

        paintDraftFromLocation: function (location) {
            this.clearDraftLayer();
            if (location.boundary_type === 'polygon' && Array.isArray(location.polygon_coordinates)) {
                const latlngs = toLatLngs(location.polygon_coordinates);
                if (latlngs.length >= 3) {
                    this.draftLayer = L.polygon(latlngs, {
                        color: COLORS.selected,
                        fillColor: COLORS.selected,
                        fillOpacity: 0.18,
                        weight: 2,
                    }).addTo(this.map);
                    this.refreshVertices();
                }
                return;
            }
            if (location.latitude != null && location.longitude != null) {
                this.draftLayer = L.circle([location.latitude, location.longitude], {
                    radius: location.radius || 80,
                    color: COLORS.selected,
                    fillColor: COLORS.selected,
                    fillOpacity: 0.18,
                    weight: 2,
                }).addTo(this.map);
            }
        },

        fitDraft: function () {
            if (!this.draftLayer) return;
            if (typeof this.draftLayer.getBounds === 'function') {
                this.map.fitBounds(this.draftLayer.getBounds(), { padding: [40, 40], maxZoom: 18 });
            } else if (typeof this.draftLayer.getLatLng === 'function') {
                this.map.setView(this.draftLayer.getLatLng(), 17);
            }
        },

        redrawOverlays: function (options) {
            if (!this.overlay) return;
            options = options || {};
            this.overlay.clearLayers();
            const bounds = [];
            const selectedOnly = (options.selectedOnly || this.showingSelectedOnly) && !this.drawing;
            this.locations.forEach((location) => {
                if (this.drawing && this.selectedId && Number(location.id) === Number(this.selectedId)) {
                    return;
                }
                if (selectedOnly && Number(location.id) !== Number(this.selectedId)) {
                    return;
                }
                const layer = this.layerForLocation(location);
                if (!layer) return;
                layer._geofenceId = location.id;
                layer.on('click', (event) => {
                    L.DomEvent.stopPropagation(event);
                    this.focusById(location.id);
                    this.call('focus', location.id);
                });
                this.overlay.addLayer(layer);
                if (Number(location.id) === Number(this.selectedId)) {
                    layer.bringToFront();
                    if (typeof layer.redraw === 'function') {
                        layer.redraw();
                    }
                }
                if (typeof layer.getBounds === 'function') {
                    bounds.push(layer.getBounds());
                }
            });
            if (options.fitAll && !this.drawing && bounds.length > 0) {
                const group = bounds[0];
                bounds.slice(1).forEach((b) => group.extend(b));
                this.map.fitBounds(group, { padding: [32, 32], maxZoom: 17 });
            } else if (!this.drawing && this.locations.length === 0) {
                this.map.setView([this.defaultCenter.lat, this.defaultCenter.lng], 16);
            }
        },

        layerForLocation: function (location) {
            const active = location.status === 'active';
            const selected = Number(location.id) === Number(this.selectedId);
            const color = selected ? COLORS.selected : (!active ? COLORS.inactive : COLORS.idle);
            const options = {
                color: color,
                fillColor: color,
                fillOpacity: active ? 0.12 : 0.06,
                weight: selected ? 3 : 2,
            };
            if (location.boundary_type === 'polygon' && Array.isArray(location.polygon_coordinates)) {
                const latlngs = toLatLngs(location.polygon_coordinates);
                if (latlngs.length < 3) return null;
                return L.polygon(latlngs, options);
            }
            if (location.latitude == null || location.longitude == null) return null;
            return L.circle([location.latitude, location.longitude], {
                ...options,
                radius: location.radius || 10,
            });
        },

        clearDraftLayer: function () {
            this.vertexMarkers.forEach((marker) => this.map.removeLayer(marker));
            this.vertexMarkers = [];
            if (this.draftLayer) {
                this.map.removeLayer(this.draftLayer);
                this.draftLayer = null;
            }
        },

        bindToolbar: function () {
            const toolbar = document.getElementById('geofence-map-toolbar');
            if (toolbar && this.map && !this._toolbarBound) {
                L.DomEvent.disableClickPropagation(toolbar);
                L.DomEvent.disableScrollPropagation(toolbar);
            }
            this._toolbarBound = true;

            const wrapper = document.querySelector('[data-search-url]');
            this.searchUrl = wrapper ? wrapper.getAttribute('data-search-url') : null;

            const search = document.getElementById('geofence-place-search');
            if (search && !search._geofenceBound) {
                search._geofenceBound = true;
                search.addEventListener('input', () => {
                    clearTimeout(this.searchTimer);
                    this.searchTimer = setTimeout(() => this.search(search.value), 350);
                });
                search.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        clearTimeout(this.searchTimer);
                        this.search(search.value);
                    }
                    if (event.key === 'Escape') {
                        this.hideSearchResults();
                    }
                });
            }

            const goBtn = document.getElementById('geofence-goto-btn');
            if (goBtn && !goBtn._geofenceBound) {
                goBtn._geofenceBound = true;
                goBtn.addEventListener('click', () => this.goFromInputs());
            }
            ['geofence-goto-lat', 'geofence-goto-lng'].forEach((id) => {
                const input = document.getElementById(id);
                if (input && !input._geofenceBound) {
                    input._geofenceBound = true;
                    input.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            this.goFromInputs();
                        }
                    });
                }
            });

            const streetBtn = document.getElementById('geofence-layer-street');
            const satBtn = document.getElementById('geofence-layer-satellite');
            if (streetBtn && !streetBtn._geofenceBound) {
                streetBtn._geofenceBound = true;
                streetBtn.addEventListener('click', () => this.setBaseLayer('street'));
            }
            if (satBtn && !satBtn._geofenceBound) {
                satBtn._geofenceBound = true;
                satBtn.addEventListener('click', () => this.setBaseLayer('satellite'));
            }
        },

        setBaseLayer: function (name) {
            if (!this.map || !this.streetLayer || !this.satelliteLayer) return;
            const next = name === 'satellite' ? 'satellite' : 'street';
            if (next === 'satellite') {
                if (this.map.hasLayer(this.streetLayer)) this.map.removeLayer(this.streetLayer);
                if (!this.map.hasLayer(this.satelliteLayer)) this.satelliteLayer.addTo(this.map);
            } else {
                if (this.map.hasLayer(this.satelliteLayer)) this.map.removeLayer(this.satelliteLayer);
                if (!this.map.hasLayer(this.streetLayer)) this.streetLayer.addTo(this.map);
            }
            this.baseLayer = next;
            this.syncLayerButtons();
        },

        syncLayerButtons: function () {
            const streetBtn = document.getElementById('geofence-layer-street');
            const satBtn = document.getElementById('geofence-layer-satellite');
            const active = 'px-3 py-1.5 font-semibold bg-[#83211b] text-white';
            const idle = 'px-3 py-1.5 font-semibold text-gray-700 hover:bg-gray-50';
            if (streetBtn) streetBtn.className = this.baseLayer === 'street' ? active : idle;
            if (satBtn) satBtn.className = this.baseLayer === 'satellite' ? active : idle;
        },

        goTo: function (lat, lng, zoom) {
            const coords = this.validateLatLng(lat, lng);
            if (!coords) {
                this.showMapError('Enter a valid latitude and longitude.');
                return false;
            }
            this.clearMapError();
            this.map.setView([coords.lat, coords.lng], zoom || 17);
            this.dropPin(coords.lat, coords.lng);
            const latInput = document.getElementById('geofence-goto-lat');
            const lngInput = document.getElementById('geofence-goto-lng');
            if (latInput) latInput.value = String(coords.lat);
            if (lngInput) lngInput.value = String(coords.lng);
            return true;
        },

        goFromInputs: function () {
            const lat = document.getElementById('geofence-goto-lat')?.value;
            const lng = document.getElementById('geofence-goto-lng')?.value;
            this.goTo(lat, lng, 17);
        },

        search: function (query) {
            const text = String(query || '').trim();
            if (text.length < 2) {
                this.hideSearchResults();
                return;
            }

            const asCoords = this.parseLatLngPair(text);
            if (asCoords) {
                this.hideSearchResults();
                this.goTo(asCoords.lat, asCoords.lng, 17);
                return;
            }

            if (!this.searchUrl) {
                this.showMapError('Place search is not available.');
                return;
            }

            const bounds = this.map ? this.map.getBounds() : null;
            const params = new URLSearchParams({ q: text });
            if (bounds) {
                params.set('viewbox', [
                    bounds.getWest(),
                    bounds.getSouth(),
                    bounds.getEast(),
                    bounds.getNorth(),
                ].join(','));
            }

            fetch(this.searchUrl + '?' + params.toString(), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((response) => {
                    if (!response.ok) throw new Error('search failed');
                    return response.json();
                })
                .then((body) => this.showSearchResults(Array.isArray(body.data) ? body.data : []))
                .catch(() => this.showMapError('Could not search that place. Try coordinates instead.'));
        },

        showSearchResults: function (results) {
            const box = document.getElementById('geofence-search-results');
            if (!box) return;
            box.innerHTML = '';
            if (!results.length) {
                box.innerHTML = '<div class="px-3 py-2 text-gray-500">No matching places</div>';
                box.classList.remove('hidden');
                return;
            }
            results.forEach((item) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'block w-full text-left px-3 py-2 hover:bg-gray-50 border-b border-gray-100 last:border-b-0';
                button.textContent = item.label;
                button.addEventListener('click', () => {
                    this.hideSearchResults();
                    const search = document.getElementById('geofence-place-search');
                    if (search) search.value = item.label;
                    this.goTo(item.lat, item.lng, 17);
                });
                box.appendChild(button);
            });
            box.classList.remove('hidden');
        },

        hideSearchResults: function () {
            const box = document.getElementById('geofence-search-results');
            if (box) {
                box.classList.add('hidden');
                box.innerHTML = '';
            }
        },

        dropPin: function (lat, lng) {
            this.clearPin();
            this.pinMarker = L.circleMarker([lat, lng], {
                radius: 8,
                color: '#fff',
                fillColor: '#83211b',
                fillOpacity: 1,
                weight: 2,
            }).addTo(this.map);
        },

        clearPin: function () {
            if (this.pinMarker) {
                this.map.removeLayer(this.pinMarker);
                this.pinMarker = null;
            }
        },

        parseLatLngPair: function (text) {
            const match = String(text).trim().match(/^(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)$/);
            if (!match) return null;
            return this.validateLatLng(match[1], match[2]);
        },

        validateLatLng: function (lat, lng) {
            const parsedLat = parseFloat(lat);
            const parsedLng = parseFloat(lng);
            if (!Number.isFinite(parsedLat) || !Number.isFinite(parsedLng)) return null;
            if (parsedLat < -90 || parsedLat > 90 || parsedLng < -180 || parsedLng > 180) return null;
            return {
                lat: Number(parsedLat.toFixed(7)),
                lng: Number(parsedLng.toFixed(7)),
            };
        },

        showMapError: function (message) {
            const el = document.getElementById('geofence-map-error');
            if (!el) return;
            el.textContent = message;
            el.classList.remove('hidden');
        },

        clearMapError: function () {
            const el = document.getElementById('geofence-map-error');
            if (!el) return;
            el.textContent = '';
            el.classList.add('hidden');
        },
    };
})();
