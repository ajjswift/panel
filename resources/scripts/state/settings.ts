import { action, Action } from 'easy-peasy';

export interface SiteSettings {
    name: string;
    /** A reseller's logo, when the request resolved to one. Null on the stock panel. */
    logo: string | null;
    locale: string;
    recaptcha: {
        enabled: boolean;
        siteKey: string;
    };
}

export interface SettingsStore {
    data?: SiteSettings;
    setSettings: Action<SettingsStore, SiteSettings>;
}

const settings: SettingsStore = {
    data: undefined,

    setSettings: action((state, payload) => {
        state.data = payload;
    }),
};

export default settings;
