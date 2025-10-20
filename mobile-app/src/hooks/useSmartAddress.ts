import { useEffect, useState } from 'react';
import { useSelector } from 'react-redux';
import { RootState } from '../store';
import { LocationAddressService } from '../services/locationAddressService';
import { LocalAddressDB } from '../services/localAddressDB';

/**
 * Hook to manage smart address input functionality
 * Handles network detection, location services, and address suggestions
 */
export const useSmartAddress = () => {
    const [isLocationEnabled, setIsLocationEnabled] = useState(false);
    const [nearbyAddresses, setNearbyAddresses] = useState<string[]>([]);
    const [currentLocation, setCurrentLocation] = useState<{latitude: number, longitude: number} | null>(null);

    // Get network and config state
    const googleMapsApiKey = useSelector((state: RootState) => 
        state.config.config?.google_maps_api_key
    );

    // Initialize location services
    useEffect(() => {
        initializeLocationServices();
    }, []);

    const initializeLocationServices = async () => {
        try {
            // Request location permission
            const hasPermission = await LocationAddressService.requestLocationPermission();
            setIsLocationEnabled(hasPermission);

            if (hasPermission) {
                // Get current location
                const location = await LocationAddressService.getCurrentLocation();
                if (location) {
                    setCurrentLocation(location);
                    
                    // Get nearby address suggestions
                    const nearby = await LocationAddressService.suggestNearbyAddresses(location);
                    setNearbyAddresses(nearby);
                }
            }
        } catch (error) {
            console.warn('Failed to initialize location services:', error);
        }
    };

    const saveAddressWithLocation = async (address: string) => {
        if (currentLocation && address.trim().length > 10) {
            try {
                // Cache address with location for future suggestions
                await LocationAddressService.cacheLocationAddress(currentLocation, address);
                
                // Also save to local database if it's a new address
                const searchResults = await LocalAddressDB.searchAddresses(address);
                if (searchResults.length === 0) {
                    // Parse address components (basic parsing)
                    const parts = address.split(',').map(part => part.trim());
                    if (parts.length >= 2) {
                        await LocalAddressDB.addAddress({
                            street: parts[0] || '',
                            city: parts[1] || 'Southington',
                            state: parts[2] || 'CT',
                            zip: parts[3] || '06489',
                            fullAddress: address,
                        });
                    }
                }
            } catch (error) {
                console.warn('Failed to save address with location:', error);
            }
        }
    };

    const refreshNearbyAddresses = async () => {
        if (isLocationEnabled && currentLocation) {
            try {
                const nearby = await LocationAddressService.suggestNearbyAddresses(currentLocation);
                setNearbyAddresses(nearby);
            } catch (error) {
                console.warn('Failed to refresh nearby addresses:', error);
            }
        }
    };

    return {
        // State
        hasGoogleMapsKey: !!googleMapsApiKey,
        isLocationEnabled,
        nearbyAddresses,
        currentLocation,
        
        // Actions
        saveAddressWithLocation,
        refreshNearbyAddresses,
        initializeLocationServices,
    };
};

export default useSmartAddress;