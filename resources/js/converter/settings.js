import { currencyApi } from './api.js';

export const providerSettingsMethods = {
    get providerCapabilities() {
        return [
            { id: 'fiat_rates', label: 'Курсы обычных валют' },
            { id: 'crypto_rates', label: 'Курсы криптовалют' },
        ];
    },
    async openProviderSettings() {
        this.providerSettingsOpen = true;
        this.providerSettingsError = '';
        if (this.providerSettings) return;

        this.providerSettingsLoading = true;
        try {
            this.providerSettings = await currencyApi.providerSettings();
        } catch (error) {
            this.providerSettingsError = error.message || 'Не удалось загрузить настройки провайдеров.';
        } finally {
            this.providerSettingsLoading = false;
        }
    },
    async saveProviderSelection(capability, providerId) {
        if (this.providerSettingsSaving) return;

        this.providerSettingsSaving = true;
        this.providerSettingsError = '';
        try {
            await currencyApi.selectProvider(capability, providerId);
            await this.reloadProviderSettings();
            await this.loadAll(true);
        } catch (error) {
            this.providerSettingsError = error.message || 'Не удалось сохранить выбор провайдера.';
            await this.reloadProviderSettings();
        } finally {
            this.providerSettingsSaving = false;
        }
    },
    async resetProviderSelection(capability) {
        if (this.providerSettingsSaving) return;

        this.providerSettingsSaving = true;
        this.providerSettingsError = '';
        try {
            await currencyApi.resetProvider(capability);
            await this.reloadProviderSettings();
            await this.loadAll(true);
        } catch (error) {
            this.providerSettingsError = error.message || 'Не удалось сбросить выбор провайдера.';
        } finally {
            this.providerSettingsSaving = false;
        }
    },
    async reloadProviderSettings() {
        this.providerSettings = await currencyApi.providerSettings();
    },
};
