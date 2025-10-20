import AsyncStorage from '@react-native-async-storage/async-storage';

const API_BASE_URL = 'https://your-wordpress-site.com/wp-json/order-manager/v1';
const CONFIG_STORAGE_KEY = '@app_config';

export interface AppConfig {
    google_maps_api_key: string;
    app_version: string;
}

/**
 * Fetch app configuration from WordPress backend
 * Requires team authentication headers
 */
export const fetchAppConfig = async (teamName: string, accessCode: string): Promise<AppConfig> => {
    try {
        const response = await fetch(`${API_BASE_URL}/config`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-Team-Name': teamName,
                'X-Access-Code': accessCode,
            },
        });

        if (!response.ok) {
            throw new Error(`Config fetch failed: ${response.status}`);
        }

        const config = await response.json();
        
        // Cache config locally for offline use
        await AsyncStorage.setItem(CONFIG_STORAGE_KEY, JSON.stringify(config));
        
        return config;
    } catch (error) {
        console.error('Error fetching app config:', error);
        
        // Try to load cached config if network fails
        const cachedConfig = await getCachedConfig();
        if (cachedConfig) {
            console.log('Using cached config due to network error');
            return cachedConfig;
        }
        
        throw error;
    }
};

/**
 * Get cached configuration from AsyncStorage
 */
export const getCachedConfig = async (): Promise<AppConfig | null> => {
    try {
        const cachedConfigString = await AsyncStorage.getItem(CONFIG_STORAGE_KEY);
        if (cachedConfigString) {
            return JSON.parse(cachedConfigString);
        }
        return null;
    } catch (error) {
        console.error('Error getting cached config:', error);
        return null;
    }
};

/**
 * Clear cached configuration
 */
export const clearCachedConfig = async (): Promise<void> => {
    try {
        await AsyncStorage.removeItem(CONFIG_STORAGE_KEY);
    } catch (error) {
        console.error('Error clearing cached config:', error);
    }
};