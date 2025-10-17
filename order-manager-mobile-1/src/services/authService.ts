import AsyncStorage from '@react-native-async-storage/async-storage';
import { API_URL } from '../config'; // Assuming you have a config file for API URL

export const login = async (code: string, teamName: string) => {
    try {
        const response = await fetch(`${API_URL}/login`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ code, teamName }),
        });

        if (!response.ok) {
            throw new Error('Login failed');
        }

        const data = await response.json();
        await AsyncStorage.setItem('userToken', data.token);
        return data;
    } catch (error) {
        console.error(error);
        throw error;
    }
};

export const logout = async () => {
    try {
        await AsyncStorage.removeItem('userToken');
    } catch (error) {
        console.error(error);
    }
};

export const isLoggedIn = async () => {
    const token = await AsyncStorage.getItem('userToken');
    return token !== null;
};