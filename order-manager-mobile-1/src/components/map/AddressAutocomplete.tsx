import React, { useState } from 'react';
import { TextInput, View, FlatList, Text, TouchableOpacity } from 'react-native';
import { GooglePlacesAutocomplete } from 'react-native-google-places-autocomplete';
import { useSelector } from 'react-redux';
import { RootState } from '../../store';

interface AddressAutocompleteProps {
    onAddressSelect?: (data: any, details: any) => void;
    placeholder?: string;
    value?: string;
    onChangeText?: (text: string) => void;
}

const AddressAutocomplete: React.FC<AddressAutocompleteProps> = ({ 
    onAddressSelect, 
    placeholder = 'Enter address',
    value,
    onChangeText 
}) => {
    const [address, setAddress] = useState(value || '');
    
    // Get Google Maps API key from Redux store
    const googleMapsApiKey = useSelector((state: RootState) => 
        state.config.config?.google_maps_api_key
    );
    
    // Show offline address input if no API key or network issues
    if (!googleMapsApiKey) {
        return (
            <View>
                <TextInput
                    placeholder={`${placeholder} (Manual Entry)`}
                    value={address}
                    onChangeText={(text) => {
                        setAddress(text);
                        onChangeText?.(text);
                    }}
                    style={{
                        height: 38,
                        color: '#5d5d5d',
                        fontSize: 16,
                        borderWidth: 1,
                        borderColor: '#ccc',
                        padding: 8,
                        backgroundColor: '#fff',
                    }}
                    multiline={true}
                    numberOfLines={2}
                />
                <Text style={{ fontSize: 12, color: '#666', marginTop: 4 }}>
                    📍 Enter full address manually (Maps offline)
                </Text>
            </View>
        );
    }

    return (
        <View>
            <GooglePlacesAutocomplete
                placeholder={placeholder}
                onPress={(data, details = null) => {
                    setAddress(data.description);
                    onChangeText?.(data.description);
                    onAddressSelect?.(data, details);
                }}
                query={{
                    key: googleMapsApiKey,
                    language: 'en',
                }}
                styles={{
                    textInputContainer: {
                        backgroundColor: '#fff',
                        borderTopWidth: 0,
                        borderBottomWidth: 0,
                    },
                    textInput: {
                        height: 38,
                        color: '#5d5d5d',
                        fontSize: 16,
                    },
                    predefinedPlacesDescription: {
                        color: '#1faadb',
                    },
                }}
                debounce={200}
                enablePoweredByContainer={false}
                textInputProps={{
                    value: address,
                    onChangeText: (text: string) => {
                        setAddress(text);
                        onChangeText?.(text);
                    }
                }}
            />
        </View>
    );
};

export default AddressAutocomplete;