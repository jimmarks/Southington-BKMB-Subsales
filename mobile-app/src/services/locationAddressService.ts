import { PermissionsAndroid, Platform } from 'react-native';
import Geolocation from '@react-native-community/geolocation';
import AsyncStorage from '@react-native-async-storage/async-storage';

interface LocationAddress {
    latitude: number;
    longitude: number;
    address: string;
    timestamp: number;
}

const LOCATION_CACHE_KEY = '@location_addresses';
const CACHE_DURATION = 24 * 60 * 60 * 1000; // 24 hours

/**
 * Use device GPS to suggest addresses based on current/recent locations
 * Useful for delivery drivers who frequent same locations
 */
export class LocationAddressService {
    
    static async requestLocationPermission(): Promise<boolean> {
        if (Platform.OS === 'android') {
            try {
                const granted = await PermissionsAndroid.request(
                    PermissionsAndroid.PERMISSIONS.ACCESS_FINE_LOCATION,
                    {
                        title: 'Location Permission',
                        message: 'This app needs location access to suggest nearby addresses.',
                        buttonNeutral: 'Ask Me Later',
                        buttonNegative: 'Cancel',
                        buttonPositive: 'OK',
                    }
                );
                return granted === PermissionsAndroid.RESULTS.GRANTED;
            } catch (err) {
                console.warn('Location permission error:', err);
                return false;
            }
        }
        return true; // iOS handles permissions differently
    }

    static async getCurrentLocation(): Promise<{latitude: number, longitude: number} | null> {
        const hasPermission = await this.requestLocationPermission();
        if (!hasPermission) return null;

        return new Promise((resolve) => {
            Geolocation.getCurrentPosition(
                (position) => {
                    resolve({
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                    });
                },
                (error) => {
                    console.warn('Location error:', error);
                    resolve(null);
                },
                { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }
            );
        });
    }

    static async cacheLocationAddress(location: {latitude: number, longitude: number}, address: string): Promise<void> {
        try {
            const cached = await this.getCachedAddresses();
            const newEntry: LocationAddress = {
                ...location,
                address,
                timestamp: Date.now(),
            };
            
            // Remove old entries and duplicates
            const filtered = cached.filter(entry => 
                entry.address !== address &&
                (Date.now() - entry.timestamp) < CACHE_DURATION
            );
            
            const updated = [newEntry, ...filtered].slice(0, 20); // Keep last 20
            await AsyncStorage.setItem(LOCATION_CACHE_KEY, JSON.stringify(updated));
        } catch (error) {
            console.warn('Failed to cache location address:', error);
        }
    }

    static async getCachedAddresses(): Promise<LocationAddress[]> {
        try {
            const stored = await AsyncStorage.getItem(LOCATION_CACHE_KEY);
            if (stored) {
                const parsed: LocationAddress[] = JSON.parse(stored);
                // Filter out expired entries
                return parsed.filter(entry => 
                    (Date.now() - entry.timestamp) < CACHE_DURATION
                );
            }
            return [];
        } catch (error) {
            console.warn('Failed to load cached addresses:', error);
            return [];
        }
    }

    static async suggestNearbyAddresses(currentLocation?: {latitude: number, longitude: number}): Promise<string[]> {
        if (!currentLocation) {
            currentLocation = await this.getCurrentLocation();
            if (!currentLocation) return [];
        }

        const cached = await this.getCachedAddresses();
        
        // Find addresses within ~0.1 miles (rough calculation)
        const nearby = cached.filter(entry => {
            const distance = this.calculateDistance(
                currentLocation!.latitude, currentLocation!.longitude,
                entry.latitude, entry.longitude
            );
            return distance < 0.1; // Within 0.1 miles
        });

        return nearby
            .sort((a, b) => b.timestamp - a.timestamp) // Most recent first
            .map(entry => entry.address)
            .slice(0, 5);
    }

    private static calculateDistance(lat1: number, lon1: number, lat2: number, lon2: number): number {
        const R = 3959; // Earth radius in miles
        const dLat = this.deg2rad(lat2 - lat1);
        const dLon = this.deg2rad(lon2 - lon1);
        const a = 
            Math.sin(dLat/2) * Math.sin(dLat/2) +
            Math.cos(this.deg2rad(lat1)) * Math.cos(this.deg2rad(lat2)) * 
            Math.sin(dLon/2) * Math.sin(dLon/2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return R * c;
    }

    private static deg2rad(deg: number): number {
        return deg * (Math.PI/180);
    }
}