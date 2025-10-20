import AsyncStorage from '@react-native-async-storage/async-storage';
import { fetchAppConfig } from './configService';

const API_URL = 'https://your-wordpress-site.com/wp-json/order-manager/v1';

export const login = async (code: string, teamName: string) => {
    try {
        const response = await fetch(`${API_URL}/auth/login`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ access_code: code, team_name: teamName }),
        });

        if (!response.ok) {
            throw new Error('Login failed');
        }

        const data = await response.json();
        
        if (data.success) {
            // Store team credentials for API authentication
            await AsyncStorage.setItem('teamName', teamName);
            await AsyncStorage.setItem('accessCode', code);
            
            // Fetch app configuration (including Google Maps API key)
            try {
                const config = await fetchAppConfig(teamName, code);
                console.log('Config fetched successfully:', config);
                return { ...data, config };
            } catch (configError) {
                console.warn('Failed to fetch config, but login successful:', configError);
                return data;
            }
        }
        
        return data;
    } catch (error) {
        console.error('Login error:', error);
        throw error;
    }
};

export const logout = async () => {
    try {
        await AsyncStorage.removeItem('teamName');
        await AsyncStorage.removeItem('accessCode');
        // Config will be cleared by Redux action
    } catch (error) {
        console.error('Logout error:', error);
    }
};

export const isLoggedIn = async () => {
    try {
        const teamName = await AsyncStorage.getItem('teamName');
        const accessCode = await AsyncStorage.getItem('accessCode');
        return teamName !== null && accessCode !== null;
    } catch (error) {
        console.error('Error checking login status:', error);
        return false;
    }
};

export const getStoredCredentials = async () => {
    try {
        const teamName = await AsyncStorage.getItem('teamName');
        const accessCode = await AsyncStorage.getItem('accessCode');
        return { teamName, accessCode };
    } catch (error) {
        console.error('Error getting stored credentials:', error);
        return { teamName: null, accessCode: null };
    }
};