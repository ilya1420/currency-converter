import { currencyApi } from './api.js';

export const providerSettingsMethods = {
    get capabilityLabels() {
        return {
            catalog: 'Каталог валют',
            fiat_rates: 'Курсы обычных валют',
            crypto_rates: 'Курсы криптовалют',
            fiat_daily_changes: 'Изменения обычных валют',
            crypto_daily_changes: 'Изменения криптовалют',
            fiat_market_data: 'Графики обычных валют',
            crypto_market_data: 'Графики криптовалют',
        };
    },
    get providerCapabilities() {
        return [
            { id: 'fiat_rates', label: 'Курсы обычных валют' },
            { id: 'crypto_rates', label: 'Курсы криптовалют' },
            { id: 'fiat_daily_changes', label: 'Изменения обычных валют' },
            { id: 'crypto_daily_changes', label: 'Изменения криптовалют' },
            { id: 'fiat_market_data', label: 'Графики обычных валют' },
            { id: 'crypto_market_data', label: 'Графики криптовалют' },
        ];
    },
    providerCapabilityLabels(provider) {
        return (provider.capabilities || []).map((capability) => this.capabilityLabels[capability] || capability);
    },
    providerDefaultName(capabilityId) {
        const capability = this.providerSettings?.capabilities?.[capabilityId];
        return capability?.providers?.find(({ id }) => id === capability.default)?.name || 'источник по умолчанию';
    },
    changeProviderSelection(capability, providerId) {
        return providerId === '__automatic__'
            ? this.resetProviderSelection(capability)
            : this.saveProviderSelection(capability, providerId);
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
        } catch (error) {
            this.providerSettingsError = error.message || 'Не удалось сохранить выбор провайдера.';
            try { await this.reloadProviderSettings(); } catch { /* Keep the original save error visible. */ }
            this.providerSettingsSaving = false;
            return;
        }
        try {
            await this.refreshAfterProviderSelection(capability);
        } catch (error) {
            this.providerSettingsError = `Выбор сохранён, но данные не обновились: ${error.message || 'источник временно недоступен.'}`;
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
        } catch (error) {
            this.providerSettingsError = error.message || 'Не удалось сбросить выбор провайдера.';
            this.providerSettingsSaving = false;
            return;
        }
        try {
            await this.refreshAfterProviderSelection(capability);
        } catch (error) {
            this.providerSettingsError = `Автоматический выбор включён, но данные не обновились: ${error.message || 'источник временно недоступен.'}`;
        } finally {
            this.providerSettingsSaving = false;
        }
    },
    async reloadProviderSettings() {
        this.providerSettings = await currencyApi.providerSettings();
    },
    async saveCoinGeckoKey() {
        if (this.providerSettingsSaving || !this.coingeckoApiKey.trim()) return;

        this.providerSettingsSaving = true;
        this.providerSettingsError = '';
        try {
            await currencyApi.saveCoinGeckoKey(this.coingeckoApiKey.trim());
            this.coingeckoApiKey = '';
            await this.reloadProviderSettings();
        } catch (error) {
            this.providerSettingsError = error.message || 'Не удалось проверить ключ CoinGecko.';
        } finally {
            this.providerSettingsSaving = false;
        }
    },
    async refreshAfterProviderSelection(capability) {
        if (capability === 'crypto_rates') await this.loadCatalog(true);
        await this.loadAll(true);
        if (capability.endsWith('_market_data') && this.activeTab === 'charts') await this.loadChart();
    },
};
