import AsyncStorage from '@react-native-async-storage/async-storage';
import { API_URL } from '../config'; // Assuming you have a config file for API URL
import { AuthResponse, UserCredentials } from '../types'; // Assuming you have defined types for responses and credentials

const authService = {
  login: async (credentials: UserCredentials): Promise<AuthResponse> => {
    try {
      const response = await fetch(`${API_URL}/login`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(credentials),
      });

      if (!response.ok) {
        throw new Error('Login failed');
      }

      const data = await response.json();
      await AsyncStorage.setItem('userToken', data.token); // Store token for authenticated requests
      return data;
    } catch (error) {
      throw error;
    }
  },

  register: async (userData: UserCredentials): Promise<AuthResponse> => {
    try {
      const response = await fetch(`${API_URL}/register`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(userData),
      });

      if (!response.ok) {
        throw new Error('Registration failed');
      }

      const data = await response.json();
      return data;
    } catch (error) {
      throw error;
    }
  },

  logout: async (): Promise<void> => {
    await AsyncStorage.removeItem('userToken'); // Clear token on logout
  },

  getToken: async (): Promise<string | null> => {
    return await AsyncStorage.getItem('userToken'); // Retrieve token for authenticated requests
  },
};

export default authService;