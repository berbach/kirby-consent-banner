/**
 * Consent Banner in the Kirby panel: the area "Consent Banner" with the
 * overview of the banner and the consent log, and the field "consentservice"
 * that searches the service database and fills the cookie information of a
 * component. Plain JavaScript with Vue templates, no build step: the panel
 * brings the template compiler along.
 */
(function () {
  const PREFIX = 'berbach.consent-banner.';

  const translate = {
    methods: {
      /**
       * A text of the plugin in the language of the user.
       */
      cb(key, replace) {
        return this.$t(PREFIX + key, replace || {});
      },
    },
  };

  /**
   * A value of the service database is a string or {de: …, en: …}.
   */
  function pickLanguage(value, language) {
    if (value === null || value === undefined) {
      return '';
    }
    if (typeof value === 'string' || typeof value === 'number') {
      return String(value);
    }
    if (typeof value === 'object') {
      if (language && value[language] !== undefined) {
        return String(value[language]);
      }
      if (value.en !== undefined) {
        return String(value.en);
      }
      if (value.de !== undefined) {
        return String(value.de);
      }
      const first = Object.values(value)[0];
      return first !== undefined ? String(first) : '';
    }
    return '';
  }

  const Navigation = {
    mixins: [translate],
    props: { current: String },
    template: `
      <k-button-group class="k-consent-banner-navigation">
        <k-button
          icon="shield"
          link="consent-banner"
          variant="filled"
          size="sm"
          :current="current === 'banner'"
          :text="cb('tab.banner')"
        />
        <k-button
          icon="checklist"
          link="consent-banner/consents"
          variant="filled"
          size="sm"
          :current="current === 'consents'"
          :text="cb('consents.headline')"
        />
      </k-button-group>
    `,
  };

  const BannerView = {
    mixins: [translate],
    components: { 'k-consent-banner-navigation': Navigation },
    props: {
      banner: Object,
      groups: { type: Array, default: () => [] },
      consents: { type: Number, default: 0 },
      pageId: String,
    },
    computed: {
      reports() {
        return [
          {
            label: this.cb('banner.status'),
            value: this.banner.active ? this.cb('banner.active') : this.cb('banner.inactive'),
            theme: this.banner.active ? 'positive' : 'notice',
            link: this.banner.link,
          },
          { label: this.cb('banner.version'), value: String(this.banner.version) },
          { label: this.cb('banner.layout'), value: this.banner.layout },
          {
            label: this.cb('banner.consents'),
            value: String(this.consents),
            link: 'consent-banner/consents',
          },
        ];
      },
    },
    methods: {
      create() {
        this.$dialog('consent-banner/create');
      },
      items(group) {
        return group.components.map((component) => ({
          text: component.title,
          info: [
            component.type,
            component.service,
            component.cookies ? this.cb('banner.cookies', { count: component.cookies }) : '',
            component.active ? '' : this.cb('banner.draft'),
          ]
            .filter(Boolean)
            .join(' · '),
          link: component.link,
          image: {
            icon: component.active ? 'toggle-on' : 'toggle-off',
            back: 'transparent',
            color: component.active ? 'green-600' : 'gray-500',
          },
        }));
      },
      groupInfo(group) {
        return [
          group.essential ? this.cb('group.essential') : '',
          group.active ? '' : this.cb('banner.draft'),
        ]
          .filter(Boolean)
          .join(' · ');
      },
    },
    template: `
      <k-panel-inside class="k-consent-banner-view">
        <k-header>
          {{ cb('banner.headline') }}
          <template #buttons>
            <k-button
              v-if="banner"
              icon="edit"
              variant="filled"
              theme="positive"
              size="sm"
              :link="banner.link"
              :text="cb('banner.edit')"
            />
            <k-button
              v-else
              icon="add"
              variant="filled"
              theme="positive"
              size="sm"
              :text="cb('action.banner.new.title')"
              @click="create"
            />
          </template>
        </k-header>

        <k-consent-banner-navigation current="banner" />

        <k-empty v-if="!banner" icon="shield" layout="cards" @click="create">
          {{ cb('banner.empty') }}
        </k-empty>

        <template v-else>
          <k-stats :reports="reports" size="large" class="k-consent-banner-stats" />

          <section class="k-section k-consent-banner-groups">
            <header class="k-section-header">
              <k-label type="section">{{ cb('banner.groups') }}</k-label>
            </header>
            <k-empty v-if="groups.length === 0" icon="layers" :link="banner.link">
              {{ cb('banner.groups.empty') }}
            </k-empty>
            <div v-for="group in groups" :key="group.link" class="k-consent-banner-group">
              <header class="k-consent-banner-group-header">
                <k-link :to="group.link" class="k-consent-banner-group-title">{{ group.title }}</k-link>
                <span v-if="groupInfo(group)" class="k-consent-banner-group-info">{{ groupInfo(group) }}</span>
              </header>
              <k-items v-if="group.components.length" :items="items(group)" layout="list" />
              <k-empty v-else icon="toggle-off" :link="group.link">
                {{ cb('banner.components.empty') }}
              </k-empty>
            </div>
          </section>
        </template>
      </k-panel-inside>
    `,
  };

  const ConsentsView = {
    mixins: [translate],
    components: { 'k-consent-banner-navigation': Navigation },
    props: {
      rows: { type: Array, default: () => [] },
      search: { type: String, default: '' },
      error: { type: String, default: '' },
      pagination: { type: Object, default: () => ({ page: 1, limit: 50, total: 0 }) },
    },
    data() {
      return { query: this.search };
    },
    watch: {
      search(value) {
        this.query = value;
      },
    },
    methods: {
      open(page, search) {
        const parameters = new URLSearchParams();
        if (search) {
          parameters.set('search', search);
        }
        if (page > 1) {
          parameters.set('page', String(page));
        }
        const query = parameters.toString();
        this.$go('consent-banner/consents' + (query ? '?' + query : ''));
      },
      submit() {
        this.open(1, String(this.query || '').trim());
      },
      reset() {
        this.query = '';
        this.open(1, '');
      },
      paginate(pagination) {
        this.open(pagination.page, this.search);
      },
    },
    template: `
      <k-panel-inside class="k-consent-banner-view k-consent-banner-consents-view">
        <k-header>{{ cb('consents.headline') }}</k-header>

        <k-consent-banner-navigation current="consents" />

        <form class="k-consent-banner-search" @submit.prevent="submit">
          <k-input
            type="text"
            icon="search"
            :value="query"
            :placeholder="cb('consents.search')"
            :spellcheck="false"
            autocomplete="off"
            @input="query = $event"
          />
          <k-button type="submit" icon="search" variant="filled" :text="cb('consents.search.submit')" />
          <k-button v-if="search" icon="cancel" variant="filled" :text="cb('consents.search.reset')" @click="reset" />
        </form>

        <k-box v-if="error" theme="negative" :text="error" />

        <k-empty v-else-if="rows.length === 0" icon="checklist">
          {{ search ? cb('consents.empty.search') : cb('consents.empty') }}
        </k-empty>

        <template v-else>
          <div class="k-table k-consent-banner-table">
            <table>
              <thead>
                <tr>
                  <th class="k-table-column">{{ cb('consents.column.id') }}</th>
                  <th class="k-table-column">{{ cb('consents.column.version') }}</th>
                  <th class="k-table-column">{{ cb('consents.column.services') }}</th>
                  <th class="k-table-column">{{ cb('consents.column.created') }}</th>
                  <th class="k-table-column">{{ cb('consents.column.updated') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rows" :key="row.id">
                  <td class="k-table-column"><code class="k-consent-banner-id">{{ row.id }}</code></td>
                  <td class="k-table-column">{{ row.version }}</td>
                  <td class="k-table-column">
                    <div v-for="group in row.groups" :key="group.title" class="k-consent-banner-services">
                      <strong>{{ group.title || cb('consents.group.none') }}</strong>
                      <ul>
                        <li v-for="(service, index) in group.services" :key="index">
                          <k-icon
                            :type="service.consent ? 'check' : 'cancel-small'"
                            :class="service.consent ? 'k-consent-banner-yes' : 'k-consent-banner-no'"
                          />
                          {{ service.title }}
                        </li>
                      </ul>
                    </div>
                  </td>
                  <td class="k-table-column">{{ row.created }}</td>
                  <td class="k-table-column">{{ row.updated }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <footer class="k-consent-banner-footer">
            <span>{{ cb('consents.total', { count: pagination.total }) }}</span>
            <k-pagination
              v-if="pagination.total > pagination.limit"
              :details="true"
              :page="pagination.page"
              :limit="pagination.limit"
              :total="pagination.total"
              @paginate="paginate"
            />
          </footer>
        </template>
      </k-panel-inside>
    `,
  };

  /**
   * The field "consentservice": a text field with suggestions from the
   * service database. A chosen service fills the fields of the cookie
   * information next to it and adds its cookies to the list; everything stays
   * editable and nothing is saved before the editor saves the page.
   */
  const ServiceField = {
    extends: 'k-text-field',
    mixins: [translate],
    data() {
      return { results: [], open: false, active: -1, searched: false, timer: null, request: 0 };
    },
    beforeDestroy() {
      window.clearTimeout(this.timer);
    },
    methods: {
      onInput(value) {
        this.$emit('input', value);
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => this.search(value), 250);
      },
      onFocus() {
        if (String(this.value || '').trim() !== '' && this.results.length > 0) {
          this.open = true;
        }
      },
      onFocusout() {
        // Later than the click on a suggestion
        window.setTimeout(() => this.close(), 150);
      },
      async search(value) {
        const query = String(value || '').trim();
        if (query === '') {
          this.close();
          this.results = [];
          return;
        }
        const request = ++this.request;
        try {
          const response = await this.$api.get('consent-banner/services', { q: query });
          if (request !== this.request) {
            return; // an answer to an older input
          }
          this.results = Array.isArray(response.results) ? response.results : [];
        } catch (error) {
          this.results = [];
        }
        this.searched = true;
        this.active = -1;
        this.open = true;
      },
      close() {
        this.open = false;
        this.active = -1;
      },
      onKeydown(event) {
        if (!this.open || this.results.length === 0) {
          return;
        }
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          this.active = (this.active + 1) % this.results.length;
        } else if (event.key === 'ArrowUp') {
          event.preventDefault();
          this.active = (this.active - 1 + this.results.length) % this.results.length;
        } else if (event.key === 'Enter' && this.active >= 0) {
          event.preventDefault();
          event.stopPropagation();
          this.select(this.results[this.active]);
        } else if (event.key === 'Escape') {
          event.stopPropagation();
          this.close();
        }
      },
      meta(service) {
        const count = Array.isArray(service.cookies) ? service.cookies.length : 0;
        return [service.service_publisher, this.cb('field.service_name.cookies', { count })]
          .filter(Boolean)
          .join(' · ');
      },
      /**
       * The language of the content that is edited, else of the user.
       */
      language() {
        const panel = this.$panel || {};
        return (
          (panel.language && panel.language.code) ||
          (panel.translation && panel.translation.code) ||
          (panel.user && panel.user.language) ||
          'en'
        );
      },
      select(service) {
        this.close();
        const language = this.language();
        const name = service.service_name || '';
        const values = {
          service_provider: pickLanguage(service.service_provider, language),
          service_publisher: pickLanguage(service.service_publisher, language),
          service_privacy_link: pickLanguage(service.service_privacy_link, language),
          service_data_collected: pickLanguage(service.service_data_collected, language),
        };
        this.$emit('input', name);

        const content = this.$panel && this.$panel.content;
        if (!content || typeof content.updateLazy !== 'function') {
          return;
        }
        // Cookies of the service the list does not have yet
        const current = content.version('changes') || {};
        const cookies = Array.isArray(current.cookies) ? current.cookies.slice() : [];
        const known = new Set(cookies.map((cookie) => (cookie.cookie_name || '') + '|' + (cookie.cookie_type || '')));
        (Array.isArray(service.cookies) ? service.cookies : []).forEach((cookie) => {
          const row = {
            cookie_name: pickLanguage(cookie.name, language),
            cookie_type: pickLanguage(cookie.type, language),
            cookie_purpose: pickLanguage(cookie.purpose, language),
            cookie_lifetime: pickLanguage(cookie.lifetime, language),
            cookie_description: pickLanguage(cookie.description, language),
          };
          const key = row.cookie_name + '|' + row.cookie_type;
          if (!known.has(key)) {
            known.add(key);
            cookies.push(row);
          }
        });
        content.updateLazy({ [this.name]: name, ...values, cookies });
      },
    },
    template: `
      <k-field
        v-bind="$props"
        :input="id"
        :counter="counterOptions"
        class="k-text-field k-consentservice-field"
      >
        <div class="k-consentservice" @keydown.capture="onKeydown" @focusin="onFocus" @focusout="onFocusout">
          <k-input
            ref="input"
            v-bind="$props"
            type="text"
            autocomplete="off"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="open ? 'true' : 'false'"
            @input="onInput"
          />
          <div v-if="open" class="k-consentservice-results" role="listbox">
            <button
              v-for="(service, index) in results"
              :key="service.service_identifier || index"
              type="button"
              role="option"
              tabindex="-1"
              class="k-consentservice-result"
              :aria-selected="index === active ? 'true' : 'false'"
              @mousedown.prevent="select(service)"
            >
              <span class="k-consentservice-result-title">{{ service.service_name }}</span>
              <span class="k-consentservice-result-meta">{{ meta(service) }}</span>
            </button>
            <p v-if="searched && results.length === 0" class="k-consentservice-empty">
              {{ cb('field.service_name.empty') }}
            </p>
          </div>
        </div>
      </k-field>
    `,
  };

  window.panel.plugin('berbach/consent-banner', {
    components: {
      'k-consent-banner-view': BannerView,
      'k-consent-banner-consents-view': ConsentsView,
    },
    fields: {
      consentservice: ServiceField,
    },
  });
})();
