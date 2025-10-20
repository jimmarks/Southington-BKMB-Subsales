import React, { useState, useEffect } from 'react';
import { View, Text } from 'react-native';
import { useSelector } from 'react-redux';
import { RootState } from '../../store';
import { GooglePlacesAutocomplete } from 'react-native-google-places-autocomplete';
import OfflineAddressInput from './OfflineAddressInput';
import NetInfo from '@react-native-community/netinfo';
import useSmartAddress from '../../hooks/useSmartAddress';

interface SmartAddressInputProps {
    onAddressSelect?: (data: any, details?: any) => void;
    placeholder?: string;
    value?: string;
    onChangeText?: (text: string) => void;
}

/**
 * Smart address input that automatically switches between:
 * 1. Google Places (when online + API key available)
 * 2. Offline manual input (when offline or no API key)
 */
const SmartAddressInput: React.FC<SmartAddressInputProps> = ({
    onAddressSelect,
    placeholder = 'Enter address',
    value,
    onChangeText
}) => {
    const [isOnline, setIsOnline] = useState(true);
    const [networkChecked, setNetworkChecked] = useState(false);
    
    // Get Google Maps API key from Redux store
    const googleMapsApiKey = useSelector((state: RootState) => 
        state.config.config?.google_maps_api_key
    );
    
    // Use smart address features
    const { saveAddressWithLocation, hasGoogleMapsKey } = useSmartAddress();

    // Monitor network connectivity
    useEffect(() => {
        const unsubscribe = NetInfo.addEventListener(state => {
            setIsOnline(state.isConnected ?? false);
            setNetworkChecked(true);
        });

        return () => unsubscribe();
    }, []);

    // Determine which input to show
    const shouldUseGooglePlaces = networkChecked && isOnline && googleMapsApiKey;

    const handleOfflineAddressSelect = async (address: string) => {
        // Save address with location data for future suggestions
        await saveAddressWithLocation(address);
        
        // Convert offline address to Google Places format for consistency
        const mockData = {
            description: address,
            place_id: null,
            structured_formatting: {
                main_text: address.split(',')[0] || address,
                secondary_text: address.split(',').slice(1).join(',') || '',
            }
        };
        onAddressSelect?.(mockData, null);
    };

    if (!networkChecked) {
        return (
            <View>
                <Text style={{ padding: 12, textAlign: 'center', color: '#666' }}>
                    Checking network...
                </Text>
            </View>
        );
    }

    if (shouldUseGooglePlaces) {
        return (
            <View>
                <GooglePlacesAutocomplete
                    placeholder={placeholder}
                    onPress={(data, details = null) => {
                        onAddressSelect?.(data, details);
                    }}
                    query={{
                        key: googleMapsApiKey,
                        language: 'en',
                        // Optimize for mobile/poor connections
                        components: 'country:us', // Restrict to US for faster results
                    }}
                    styles={{
                        textInputContainer: {
                            backgroundColor: '#fff',
                            borderTopWidth: 0,
                            borderBottomWidth: 0,
                        },
                        textInput: {
                            height: 44,
                            color: '#5d5d5d',
                            fontSize: 16,
                            borderWidth: 1,
                            borderColor: '#ddd',
                            borderRadius: 8,
                            paddingHorizontal: 12,
                        },
                        predefinedPlacesDescription: {
                            color: '#1faadb',
                        },
                    }}
                    debounce={300} // Increase debounce for poor connections
                    enablePoweredByContainer={false}
                    textInputProps={{
                        value: value,
                        onChangeText: onChangeText,
                    }}
                    // Add timeout for slow connections
                    timeout={5000}
                    onFail={(error) => {
                        console.warn('Google Places failed, falling back to offline:', error);
                        // Could trigger fallback to offline mode here
                    }}
                />
                <Text style={{ fontSize: 12, color: '#666', marginTop: 4 }}>
                    🌐 Using Google Maps (Online)
                </Text>
            </View>
        );
    }

    // Offline or no API key - use manual input
    return (
        <OfflineAddressInput
            placeholder={placeholder}
            value={value}
            onChangeText={onChangeText}
            onAddressSelect={handleOfflineAddressSelect}
        />
    );
};

export default SmartAddressInput;