import { useEffect } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { RootState } from '../store';
import { setConfigLoading, setConfigSuccess, setConfigError } from '../store/configSlice';
import { fetchAppConfig, getCachedConfig } from '../services/configService';

/**
 * Hook to manage app configuration loading
 * Automatically fetches config when team credentials are available
 */
export const useAppConfig = () => {
    const dispatch = useDispatch();
    const { isAuthenticated, teamName, code } = useSelector((state: RootState) => state.auth);
    const config = useSelector((state: RootState) => state.config);

    // Load cached config on app start
    useEffect(() => {
        const loadCachedConfig = async () => {
            try {
                const cachedConfig = await getCachedConfig();
                if (cachedConfig) {
                    dispatch(setConfigSuccess(cachedConfig));
                }
            } catch (error) {
                console.warn('Failed to load cached config:', error);
            }
        };

        if (!config.config) {
            loadCachedConfig();
        }
    }, []);

    // Fetch fresh config when authenticated
    useEffect(() => {
        const loadConfig = async () => {
            if (!teamName || !code) return;

            dispatch(setConfigLoading(true));
            
            try {
                const appConfig = await fetchAppConfig(teamName, code);
                dispatch(setConfigSuccess(appConfig));
            } catch (error) {
                const errorMessage = error instanceof Error ? error.message : 'Failed to load config';
                dispatch(setConfigError(errorMessage));
                console.error('Config loading failed:', error);
            }
        };

        if (isAuthenticated && teamName && code && !config.config) {
            loadConfig();
        }
    }, [isAuthenticated, teamName, code, dispatch, config.config]);

    return {
        googleMapsApiKey: config.config?.google_maps_api_key,
        appVersion: config.config?.app_version,
        isLoading: config.isLoading,
        error: config.error,
        hasConfig: !!config.config,
    };
};

export default useAppConfig;